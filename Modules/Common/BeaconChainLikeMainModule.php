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
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra', 'extra_indexed'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra_indexed'];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        'a' => 'Attestation rewards',
        'p' => 'Proposer reward',
        'o' => 'Orphaned or missed block (no rewards for proposer)',
        'w' => "Withdrawal",
        'd' => "New deposit",
        'sa' => "Reward for slashing attestor",
        'sp' => "Reward for slashing proposer",
        'ap' => "Attestor penalty",
        'pp' => 'Proposer penalty'
        // nikzh: где слешинг
    ];

    private const WHISTLEBLOWER_REWARD_QUOTIENT = 512;

    private const ALTAIR_FORK_EPOCH = 74240;
    private const BELLATRIX_FORK_EPOCH = 144896;
    private const PHASE0_FORK_EPOCH = 0;

    private const MIN_SLASHING_PENALTY_QUOTIENT = 128;
    private const MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR = 64;
    private const MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX = 32;
    

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
            result_in: 'data');

        return intdiv((int)$result[0]['header']['message']['slot'], 32) - 2;
    }

    public function ensure_block($block, $break_on_first = false) 
    {
        $hashes = [];

        $block_start = $block * 32; 
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

    private function checkIfValidatorSlashed($validator_index, $slot_id)
    {
        $slot_id -= 1;
        $state = requester_single(
            $this->select_node(),
            endpoint: "/eth/v1/beacon/states/{$slot_id}/validators/{$validator_index}",
            timeout: $this->timeout,
            result_in: 'data',
        );
        if($state['validator']['slashed'] === true) {
            return true;
        }
        return false;
    }
    // change slot amount to const 
    private function ask4slashedValidators($attestationGroup = [], $slot = 'head')
    {
        $slashed_validators = [];
        foreach ($attestationGroup as $at)
            $rq_validator_info[] = requester_multi_prepare($this->select_node(), endpoint: "/eth/v1/beacon/states/{$slot}/validators/{$at}");

        $rq_validator_info_multi = requester_multi(
            $rq_validator_info,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200],
        );

        foreach ($rq_validator_info_multi as $v) {
            $slash_penalty = 0;
            $validator_info = requester_multi_process($v, result_in: 'data');
            if ($validator_info['validator']['slashed'] === true && !$this->checkIfValidatorSlashed($validator_info['index'], $slot)) {

                if ((int)($slot / 32) > self::ALTAIR_FORK_EPOCH && (int)($slot / 32) < self::BELLATRIX_FORK_EPOCH) // it's altair epoch
                { 
                    $slash_penalty = (int)($validator_info['validator']['effective_balance'] / self::MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR);
                }
                if ((int)($slot / 32) > self::PHASE0_FORK_EPOCH && (int)($slot / 32) < self::ALTAIR_FORK_EPOCH)   // it's Phase0 epoch
                {
                    $slash_penalty = (int)($validator_info['validator']['effective_balance'] / self::MIN_SLASHING_PENALTY_QUOTIENT);
                }
                if((int)($slot / 32) >= self::BELLATRIX_FORK_EPOCH) // it's Bellatrix epoch and others
                {
                    $slash_penalty = (int)($validator_info['validator']['effective_balance'] / self::MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX);
                }
                $slashed_validators[$validator_info['index']] = strval($slash_penalty);
            }
        }
        return $slashed_validators;
    }

    // CAPELLA_FORK_EPOCH = 194048
    //

    // Phase 0
    //
    // MIN_SLASHING_PENALTY_QUOTIENT = 128 
    // Phase0_FORK_EPOCH = 0
    //
    // decrease_balance(state, slashed_index, validator.effective_balance // MIN_SLASHING_PENALTY_QUOTIENT)
    //
    // if whistleblower_index is None:
    //     whistleblower_index = proposer_index
    // whistleblower_reward = Gwei(validator.effective_balance // WHISTLEBLOWER_REWARD_QUOTIENT)
    // proposer_reward = Gwei(whistleblower_reward // PROPOSER_REWARD_QUOTIENT)
    // increase_balance(state, proposer_index, proposer_reward)
    // increase_balance(state, whistleblower_index, Gwei(whistleblower_reward - proposer_reward))

    // Bellatrix
    //
    // MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX = 32
    // BELLATRIX_FORK_EPOCH = 144896 // epoch
    //
    // slashing_penalty = validator.effective_balance // MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX  # [Modified in Bellatrix]
    // decrease_balance(state, slashed_index, slashing_penalty)
    //
    // if whistleblower_index is None:
    //     whistleblower_index = proposer_index
    // whistleblower_reward = Gwei(validator.effective_balance // WHISTLEBLOWER_REWARD_QUOTIENT)
    // proposer_reward = Gwei(whistleblower_reward * PROPOSER_WEIGHT // WEIGHT_DENOMINATOR)
    // increase_balance(state, proposer_index, proposer_reward)
    // increase_balance(state, whistleblower_index, Gwei(whistleblower_reward - proposer_reward))

    // Altair
    //
    // MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR = 64
    // ALTAIR_FORK_EPOCH = 74240 // epoch
    //
    // decrease_balance(state, slashed_index, validator.effective_balance // MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR)
    //
    // if whistleblower_index is None:
    //     whistleblower_index = proposer_index
    // whistleblower_reward = Gwei(validator.effective_balance // WHISTLEBLOWER_REWARD_QUOTIENT)
    // proposer_reward = Gwei(whistleblower_reward * PROPOSER_WEIGHT // WEIGHT_DENOMINATOR)
    // increase_balance(state, proposer_index, proposer_reward)
    // increase_balance(state, whistleblower_index, Gwei(whistleblower_reward - proposer_reward))

    final public function pre_process_block($block) // $block here is really an epoch number
    {
        $events = [];
        $rewards = [];
        $rq_blocks = [];
        $rq_committees = [];
        $rq_blocks_data = [];
        $rq_committees_data = [];
        $rq_slot_time = [];
        $withdrawals = []; // [i] -> [validator, address, amount, slot]
        $deposits = []; // [i] -> [validator_index, address, amount, slot]
        $attestors_slashing = []; // [validator] -> [[validator_index => penalty], slot, amount]
        $proposers_slashing = []; // [validator] -> [[validator_index => penalty], slot, amount]
        $rewards_slots = []; // [validator] -> [slot, reward]
        $slot_data = [];

        $proposers = requester_single($this->select_node(),
            endpoint: "eth/v1/validator/duties/proposer/{$block}",
            timeout: $this->timeout,
            result_in: 'data',
        );

        foreach ($proposers as $proposer)
        {
            $slots[$proposer['slot']] = null;
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
            $slot_data[] = requester_multi_process($slot);
        }

        foreach ($slot_data as $slot_info)
        {
            if (isset($slot_info['code']) && $slot_info['code'] === '404')
                continue;
            elseif (isset($slot_info['code']))
                throw new ModuleError('Unexpected response code');

            $proposer = $slot_info['data']['message']['proposer_index'];
            $slot_id = (string)$slot_info['data']['message']['slot'];
            if (isset($slot_info['data']['message']['body']['execution_payload'])) {
                $timestamp = (int)$slot_info['data']['message']['body']['execution_payload']['timestamp'];
                $withdrawal = $slot_info['data']['message']['body']['execution_payload']["withdrawals"];
            } else {
                $timestamp = 0;
                $withdrawal = [];
            }

            $slots[$slot_id] = $timestamp;
            
            foreach ($withdrawal as $w) {
                $withdrawals[] = [
                    $w["validator_index"],
                    $w["address"],
                    $w["amount"],
                    $slot_id
                ];
            }
            $deposit = $slot_info['data']['message']['body']['deposits'];
            foreach($deposit as $d) {
                $pubkey = $d["data"]["pubkey"];
                $address = $d["data"]["withdrawal_credentials"];
                $amount = $d["data"]["amount"];
                $index = requester_single($this->select_node(),
                endpoint: "/eth/v1/beacon/states/{$slot_id}/validators/{$pubkey}",
                timeout: $this->timeout,
                result_in: 'data')["index"];
                $deposits[] = [$index, $address, $amount, $slot_id];
            }
            $attester_slashings = $slot_info['data']['message']['body']['attester_slashings'];
            foreach($attester_slashings as $as) {
                $attestation_1 = $as['attestation_1']['attesting_indices'];
                $attestation_2 = $as['attestation_2']['attesting_indices'];
                $slashed_1 = $this->ask4slashedValidators(attestationGroup: $attestation_1, slot: $slot_id);
                $slashed_2 = $this->ask4slashedValidators(attestationGroup: $attestation_2, slot: $slot_id);
                $slashed = $slashed_1 + $slashed_2;
                if (count($slashed) > 0) {
                    if (isset($attestors_slashing[$proposer])) {
                        $attestors_slashing[$proposer][0] = $attestors_slashing[$proposer][0] + $slashed;
                    } else {
                        $attestors_slashing[$proposer][0] = $slashed;
                        $attestors_slashing[$proposer][1] = $slot_id;
                    }
                }
            }
            $proposer_slashings = $slot_info['data']['message']['body']['proposer_slashings'];
            foreach($proposer_slashings as $as) {
                $attestation_1 = $as['signed_header_1']["message"]['proposer_index'];
                $attestation_2 = $as['signed_header_2']["message"]['proposer_index'];
                $slashed_1 = $this->ask4slashedValidators(attestationGroup: [$attestation_1], slot: $slot_id);
                $slashed_2 = $this->ask4slashedValidators(attestationGroup: [$attestation_2], slot: $slot_id);
                $slashed = $slashed_1 + $slashed_2;
                if (count($slashed) > 0) {
                    if (isset($proposers_slashing[$proposer])) {
                        $proposers_slashing[$proposer][0] = $proposers_slashing[$proposer][0] + $slashed;
                    } else {
                        $proposers_slashing[$proposer][0] = $slashed;
                        $proposers_slashing[$proposer][1] = $slot_id;
                    }
                }
            }
        }

        $this->block_time = date('Y-m-d H:i:s', $slots[max(array_keys($slots))]);

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
                no_json_encode: true
            );

        $rq_committees_multi = requester_multi($rq_committees,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [200, 404, 500]
        );

        foreach ($rq_committees_multi as $v)
            $rq_committees_data[] = requester_multi_process($v);

        foreach ($rq_blocks_data as $rq)
        {
            if (isset($rq['code']) && $rq['code'] === '404')
                continue;
            elseif (isset($rq['code']))
                throw new ModuleError('Unexpected response code');

            $proposer_index = $rq['data']['proposer_index'];
            $rewards_slots[$proposer_index][1] = bcadd($rq['data']["attestations"], $rq['data']['sync_aggregate']);
            if(isset($attestors_slashing[$proposer_index])) {
                $attestors_slashing[$proposer_index][2] = $rq['data']["attester_slashings"];
            }
            if(isset($proposer_slashings[$proposer_index])) {
                $proposer_slashings[$proposer_index][2] = $rq['data']["proposer_slashings"];
            }
        }

        foreach ($rq_committees_data as $slot_rewards)
        {
            if (isset($slot_rewards['code']) && ($slot_rewards['code'] === '404' || $slot_rewards['code'] === '500'))
                continue;
            elseif (isset($slot_rewards['code']))
                throw new ModuleError('Unexpected response code');
            
            $slot_rewards = $slot_rewards['data'];
            foreach ($slot_rewards as $rw)
            {
                if (isset($rewards[$rw["validator_index"]]))
                    $rewards[$rw['validator_index']] = bcadd($rw['reward'], $rewards[$rw['validator_index']]);
                else
                    $rewards[$rw['validator_index']] = $rw['reward'];
            }
        }

        $key_tes = 0;

        foreach($attestors_slashing as $index => [$slashed, $slot, $reward]) {
            $slashed_indexes = array_keys($slashed);
            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => 'the-void',
                'effect' => '-' . $reward,
                'extra' => 'sa',
                'extra_indexed' => $slashed_indexes
            ];

            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => $index,
                'effect' => $reward,
                'extra' => 'sa',
                'extra_indexed' => $slashed_indexes
            ];
            foreach($slashed as $validator_index => $penalty) {    
                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => $validator_index,
                    'effect' => '-' . $penalty,
                    'extra' => 'ap',
                    'extra_indexed' => null
                ];
                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => 'the-void',
                    'effect' => $penalty,
                    'extra' => 'ap',
                    'extra_indexed' => null
                ];
            }
        }

        foreach($proposer_slashings as $index => [$slashed, $slot, $reward]) {
            $slashed_indexes = array_keys($slashed);
            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => 'the-void',
                'effect' => '-' . $reward,
                'extra' => 'sp',
                'extra_indexed' => $slashed_indexes
            ];

            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => $index,
                'effect' => $reward,
                'extra' => 'sp',
                'extra_indexed' => $slashed_indexes
            ];
            foreach($slashed as $validator_index => $penalty) {    
                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => $validator_index,
                    'effect' => '-' . $penalty,
                    'extra' => 'pp',
                    'extra_indexed' => null
                ];
                $events[] = [
                    'block' => $block,
                    'transaction' => $slot,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => 'the-void',
                    'effect' => $penalty,
                    'extra' => 'pp',
                    'extra_indexed' => null
                ];
            }
        }

        foreach ($withdrawals as $i => [$index, $address, $amount, $slot]) {
            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => 'the-void',
                'effect' => '-' . $amount,
                'extra' => 'w',
                'extra_indexed' => $address
            ];

            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => $index,
                'effect' => $amount,
                'extra' => 'w',
                'extra_indexed' => $address
            ];
        }

        foreach ($deposits as $i => [$index, $address, $amount, $slot]) {
            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => $index,
                'effect' => '-' . $amount,
                'extra' => 'd',
                'extra_indexed' => $address
            ];

            $events[] = [
                'block' => $block,
                'transaction' => $slot,
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => 'the-void',
                'effect' => $amount,
                'extra' => 'd',
                'extra_indexed' => $address
            ];
        }

        foreach ($rewards_slots as $validator => $info) {
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
                'time' => $this->block_time,
                'address' => 'the-void',
                'effect' => '-' . $effect,
                'extra' => $extra,
                'extra_indexed' => null
            ];

            $events[] = [
                'block' => $block,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => $this->block_time,
                'address' => $validator,
                'effect' => $effect,
                'extra' => $extra,
                'extra_indexed' => null
            ];
        }

        $attestations = requester_single($this->select_node(),
            endpoint: "eth/v1/beacon/rewards/attestations/{$block}",
            params: '[]',
            no_json_encode: true,
            timeout: 1800,
            valid_codes: [200, 500]
        );

        if (isset($attestations['code']) && $attestations['code'] === '500')
            $attestations['total_rewards'] = [];
        elseif (isset($attestations['code']))
            throw new ModuleError('Unexpected response code');
        elseif(!isset($attestations["code"])) {
            $attestations = $attestations["data"];
        }

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

        foreach ($rewards as $validator => $reward)
        {
            $this_void = bcmul($reward, '-1');
            $this_void = ($this_void === '0') ? '-0' : $this_void;

            if (str_contains($this_void, '-'))
            {
                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => 'the-void',
                    'effect' => $this_void,
                    'extra' => 'a',
                    'extra_indexed' => null
                ];

                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => $validator,
                    'effect' => $reward,
                    'extra' => 'a',
                    'extra_indexed' => null
                ];
            }
            else
            {
                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => $validator,
                    'effect' => $reward,
                    'extra' => 'a',
                    'extra_indexed' => null
                ];

                $events[] = [
                    'block' => $block,
                    'transaction' => null,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => 'the-void',
                    'effect' => $this_void,
                    'extra' => 'a',
                    'extra_indexed' => null
                ];
            }
        }

        $this->set_return_events($events);
    }

    public function api_get_balance($index)
    {
        return requester_single($this->select_node(),
            endpoint: "eth/v1/beacon/states/head/validators/{$index}",
            timeout: $this->timeout)['data']['balance'];
    }
}
