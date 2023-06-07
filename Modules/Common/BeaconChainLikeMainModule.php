<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes rewards and punishments happening on the Beacon Chain.
 *  It requires a Prysm-like node to run.  */

abstract class BeaconChainLikeMainModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric; // nikzh: тут будут только цифры
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even; // nikzh: а точно всегда +-?
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None; // nikzh: комиссий тут нет
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true; // nikzh: а может быть такое, что ни одного эвента эпоха не сгенерила?

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        'a' => 'Attestation rewards',
        'p' => 'Proposer reward',
        'o' => 'Orphaned or missed block (no rewards for proposer)',
        // nikzh: где слешинг
    ];

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
    }

    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(),
            endpoint: 'eth/v1/beacon/headers',
            timeout: $this->timeout,
            result_in: 'data'); // Retrieve the latest slot number

        return intdiv($result['header']['message']['slot'], 32) - 2;
        // We wait for 2 epochs to be sure we get finalized epochs
    }

    public function ensure_block($block, $break_on_first = false) // We use epochs here instead of blocks
    {
        $hashes = [];

        $block_start = $block * 32; // $block here is really an epoch number
        $block_end = $block_start + 31;

        foreach ($this->nodes as $node)
        {
            $multi_curl = [];

            for ($i = $block_start; $i <= $block_end; $i++)
            {
                $multi_curl[] = requester_multi_prepare($node,
                    endpoint: "eth/v1/beacon/headers/{$i}",
                    timeout: $this->timeout,
                );
            }

            $curl_results = requester_multi(
                $multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout,
                valid_codes: [200, 404],
            );

            foreach ($curl_results as $result)
            {
                $hash_result = requester_multi_process($result);

                if (isset($hash_result['code']) && $hash_result['code'] === '404')
                    continue;
                elseif (isset($hash_result['code']))
                    throw new ModuleError('Unexpected response code');

                $root = $hash_result['data']['root'];
                $slot = $hash_result['data']['header']['message']['slot'];

                $hashes_res[$slot] = $root;
            }

            ksort($hashes_res);
            $hash = join($hashes_res);
            $hashes[] = $hash;

            if ($break_on_first)
                break;
        }

        if (isset($hashes[1]))
            for ($i = 1; $i < count($hashes); $i++)
                if ($hashes[0] !== $hashes[$i])
                    throw new ConsensusException("ensure_block(block_id: {$block}): no consensus");

        $this->block_hash = $hashes[0];
        $this->block_id = $block;
    }

    final public function pre_process_block($block) // $block here is really an epoch number
    {
        $events = [];
        $rewards = [];
        $rewards_slots = []; // key - validator, [key] -> [slot, reward]
        $rq_blocks = [];
        $rq_committees = [];
        $rq_blocks_data = [];
        $rq_committees_data = [];
        $rq_slot_time = [];

        $proposers = requester_single($this->select_node(),
            endpoint: "eth/v1/validator/duties/proposer/{$block}",
            timeout: $this->timeout,
            result_in: 'data',
        );

        foreach ($proposers as $proposer)
        {
            $slots[$proposer['slot']] = 0;
            $rewards_slots[$proposer['validator_index']] = [$proposer['slot'], null];
        }

        foreach ($slots as $slot => $tm)
            $rq_slot_time[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/blocks/{$slot}");

        $rq_slot_time_multi = requester_multi($rq_slot_time,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );

        foreach ($rq_slot_time_multi as $slot)
        {
            $slot_info = requester_multi_process($slot);

            if (isset($slot_info['code']) && $slot_info['code'] === '404')
                continue;
            elseif (isset($slot_info['code']))
                throw new ModuleError('Unexpected response code');

            $slot_id = $slot_info['data']['message']['slot'];
            $timestamp = (int)$slot_info['data']['message']['body']['execution_payload']['timestamp'];

            $slots[$slot_id] = $timestamp;
        }

        foreach ($slots as $slot => $tm)
            $rq_blocks[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/rewards/blocks/{$slot}");

        $rq_blocks_multi = requester_multi($rq_blocks,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );

        foreach ($rq_blocks_multi as $v)
            $rq_blocks_data[] = requester_multi_process($v);

        foreach ($slots as $slot => $tm)
            $rq_committees[] = requester_multi_prepare(
                $this->select_node(),
                endpoint: "eth/v1/beacon/rewards/sync_committee/{$slot}",
                params: '[]',
            );

        $rq_committees_multi = requester_multi($rq_committees,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );

        foreach ($rq_committees_multi as $v)
            $rq_committees_data[] = requester_multi_process($v, result_in: 'data');

        foreach ($rq_blocks_data as $rq)
        {
            if (isset($rq['code']) && $rq['code'] === '404')
                continue;
            elseif (isset($rq['code']))
                throw new ModuleError('Unexpected response code');

            $proposer_index = $rq['data']['proposer_index'];
            $rewards_slots[$proposer_index][1] = $rq['data']['total'];
        }

        foreach ($rq_committees_data as $slot_rewards)
        {
            foreach ($slot_rewards as $rw)
            {
                if (isset($rewards[$rw["validator_index"]]))
                    $rewards[$rw['validator_index']] = bcadd($rw['reward'], $rewards[$rw['validator_index']]);
                else
                    $rewards[$rw['validator_index']] = $rw['reward'];
            }
        }

        $key_tes = 0;
        $last_slot = max(array_keys($slots));

        foreach ($rewards_slots as $validator => $info)
        {
            $extra = 'p';

            if ($slots[$info[0]] === 0)
                $extra = 'o';

            $effect = $info[1];

            if (is_null($effect))
                $effect = '0';

            $events[] = [ // nikzh: сначала минус, потом плюс
                'block' => $block,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$info[0]]),
                'address' => 'the-void',
                'effect' => '-' . $effect,
                'extra' => $extra,
            ];

            $events[] = [
                'block' => $block,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$info[0]]),
                'address' => $validator,
                'effect' => $effect,
                'extra' => $extra,
            ];
        }

        $attestations = requester_single($this->select_node(),
            endpoint: "eth/v1/beacon/rewards/attestations/{$block}",
            params: '[]',
            no_json_encode: true,
            timeout: 1800,
            result_in: 'data',
        );

        foreach ($attestations['total_rewards'] as $attestation)
        {
            if (isset($rewards[$attestation['validator_index']]))
            {
                $rewards[$attestation['validator_index']] =
                    bcadd(bcadd(bcadd($attestation['head'],
                        $attestation['target']),
                        $attestation['source']),
                        $rewards[$attestation['validator_index']]);
            }
            else
            {
                $rewards[$attestation['validator_index']] =
                    bcadd(bcadd($attestation['head'],
                        $attestation['target']),
                        $attestation['source']);
            }
        }

        foreach($rewards as $validator => $reward)
        {
            $this_void = bcmul($reward, '-1');
            $this_void = ($this_void === '0') ? '-0' : $this_void;

            if (str_contains($this_void, '-'))
            {
                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => date('Y-m-d H:i:s', $slots[$last_slot]),
                    'address' => 'the-void',
                    'effect' => $this_void,
                    'extra' => 'a',
                ];

                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => date('Y-m-d H:i:s', $slots[$last_slot]),
                    'address' => $validator,
                    'effect' => $reward,
                    'extra' => 'a'
                ];
            }
            else
            {
                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => date('Y-m-d H:i:s', $slots[$last_slot]),
                    'address' => $validator,
                    'effect' => $reward,
                    'extra' => 'a'
                ];

                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => date('Y-m-d H:i:s', $slots[$last_slot]),
                    'address' => 'the-void',
                    'effect' => $this_void,
                    'extra' => 'a',
                ];
            }
        }

        $this->block_time = end($slots[$last_slot]);
        $this->set_return_events($events);
    }

    public function api_get_balance($index)
    {
        return requester_single($this->select_node(),
            endpoint: "eth/v1/beacon/states/head/validators/{$index}",
            timeout: $this->timeout)['data']['balance'];
    }
}
