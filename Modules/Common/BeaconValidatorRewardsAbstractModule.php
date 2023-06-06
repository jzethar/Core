<?php declare(strict_types = 1);


abstract class BeaconValidatorRewardsAbstractModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public ?string $parent_root = null;
    private static $events_prev = [];
    private static $epoch_prev = 0;

    private ?string $epoch = null;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        // if (is_null($this->workchain)) throw new DeveloperError("`workchain` is not set");
    }

    // get number of fin epoch 
    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(), endpoint: 'eth/v1/beacon/headers', timeout: $this->timeout);
        $slot = $this->get_header_slot($result);
        $epoch = ((int)($slot / 32)) - 2;
        return $epoch;
    }

    private function get_header_slot($header)
    {
        if (array_key_exists("data", $header)) {
            $result = $header["data"];
            if (count($result) == 1) {
                $result = $result[0];
                if (array_key_exists("header", $result)) {
                    $result = $result["header"];
                    if (array_key_exists("message", $result)) {
                        $result = $result["message"];
                        if (array_key_exists("slot", $result)) {
                            return (int)$result["slot"];
                        }
                    }
                }
            }
        }
    }


    // merge all hashes of blocks in epoch 
    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, endpoint: "eth/v1/beacon/headers/{$block_id}", timeout: $this->timeout);

            if ($break_on_first)
                break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $hashes = requester_multi_process($curl_results[0]);
        ksort($hashes, SORT_STRING);

        if(array_key_exists("data", $hashes)) {
            $hashes = $hashes["data"];
            if(array_key_exists("root", $hashes)) {
                $this->block_hash = $hashes["root"];
            } else {
                throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
            }
        } else {
            throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
        }

        if (count($curl_results) > 0)
        {
            foreach ($curl_results as $result)
            {
                $this_hashes = requester_multi_process($result);
                ksort($this_hashes, SORT_STRING);
                $this_final_hash = "";

                if(array_key_exists("data", $this_hashes)) {
                    $this_hashes = $this_hashes["data"];
                    if(array_key_exists("root", $this_hashes)) {
                        $this_final_hash = $this_hashes["root"];
                    } else {
                        throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                    }
                } else {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }

                if (strtolower($this_final_hash) !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                } else {
                    if(array_key_exists("header", $this_hashes)) {
                        $this_hashes = $this_hashes["header"];
                        if(array_key_exists("message", $this_hashes)) {
                            $this_hashes = $this_hashes["message"];
                            if(array_key_exists("parent_root", $this_hashes)) {
                                $this->parent_root = $this_hashes["parent_root"];
                                return;
                            }
                        }
                    }
                    throw new Exception("no field");
                }
            }
        }
    }

    // okey, let's think that $block_id here is a latest block of blockchain
    final public function pre_process_block($epoch)
    {
        $events = [];
        $rewards = [];
        $rewards_slots = []; // key - validator, [key] - [slot, reward]
        $rq_blocks = [];
        $rq_committees = [];
        $rq_blocks_data = [];
        $rq_committees_data = [];
        $rq_slot_time = [];
        
        if($epoch == static::$epoch_prev) {
            return static::$events_prev;
        }

        $proposers = requester_single(
            $this->select_node(),
            endpoint: "eth/v1/validator/duties/proposer/{$epoch}",
            timeout: $this->timeout
        );

        if(array_key_exists("data", $proposers)) {
            foreach($proposers["data"] as $proposer) {
                $slots[$proposer["slot"]] = 0;
                $rewards_slots[$proposer["validator_index"]] = [$proposer["slot"], ""];
            }
        }

        foreach($slots as $slot => $tm) {
            $rq_slot_time[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/blocks/{$slot}");
        }
        $rq_slot_time_multi = requester_multi(
            $rq_slot_time,
            20,
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );
        foreach($rq_slot_time_multi as $slot) {
            $slot_info = requester_multi_process($slot, ignore_errors: true);
            if(array_key_exists("code", $slot_info)) {
                $code = $slot_info["code"];
                if($code == "404") {
                    continue;
                }
            } else {
                if (array_key_exists("data", $slot_info)) {
                    $data = $slot_info["data"];
                    if (array_key_exists("message", $data)) {
                        $data = $data["message"];
                        $slot_id = $data["slot"];
                        if (array_key_exists("execution_payload", $data["body"])) {
                            $execution_payload = $data["body"]["execution_payload"];
                            if (array_key_exists("timestamp", $execution_payload)) {
                                $slots[$slot_id] = (int)$execution_payload["timestamp"];
                            }
                        }
                        
                    }
                }
            }
        }

        foreach ($slots as $slot => $tm) {
            $rq_blocks[] = requester_multi_prepare($this->select_node(), endpoint: "eth/v1/beacon/rewards/blocks/{$slot}");
        }
        $rq_blocks_multi = requester_multi(
            $rq_blocks,
            20,
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );
        foreach ($rq_blocks_multi as $v) {
            $rq_blocks_data[] = requester_multi_process($v, ignore_errors: true);
        }

        foreach ($slots as $slot => $tm) {
            $rq_committees[] = requester_multi_prepare(
                $this->select_node(),
                endpoint: "eth/v1/beacon/rewards/sync_committee/{$slot}",
                params: "[]"
            );
        }
        $rq_committees_multi = requester_multi(
            $rq_committees,
            20,
            timeout: $this->timeout,
            valid_codes: [200, 404],
        );
        foreach ($rq_committees_multi as $v) {
            $rq_committees_data[] = requester_multi_process($v, ignore_errors: true);
        }

        foreach ($rq_blocks_data as $rq) {
            if (array_key_exists("data", $rq)) {
                $slot_rewards = $rq["data"];
                $proposer_index = $slot_rewards["proposer_index"];
                $rewards_slots[$proposer_index][1] = $slot_rewards["total"];
            }
        }

        foreach ($rq_committees_data as $rq) {
            if (array_key_exists("data", $rq)) {
                $slot_rewards = $rq["data"];
                foreach ($slot_rewards as $rw) {
                    if (array_key_exists($rw["validator_index"], $rewards)) {
                        $rewards[$rw["validator_index"]] += $rw["reward"];
                    } else {
                        $rewards[$rw["validator_index"]] = $rw["reward"];
                    }
                }
            }
        }

        $key_tes = 0;

        foreach ($rewards_slots as $validator => $info) {
            $extra = "p";
            if ($slots[$info[0]] == 0) {
                $extra = "om";
            }
            $events[] = [
                'block' => $epoch,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$info[0]]),
                'address' => $validator,
                'effect' => $info[1],
                'extra' => $extra
            ];
            $events[] = [
                'block' => $epoch,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$info[0]]),
                'address' => "the-void",
                'effect' => "-" . $info[1],
                'extra' => $extra
            ];
        }

        echo count($rewards);
        // transaction null 

        $attestations = requester_single(
            $this->select_node(),
            endpoint: "eth/v1/beacon/rewards/attestations/{$epoch}",
            params: "[]",
            no_json_encode: true,
            timeout: 6000
        );

        if (array_key_exists("data", $attestations)) {
            if (array_key_exists("total_rewards", $attestations["data"])) {
                $attestations = $attestations["data"]["total_rewards"];
                foreach ($attestations as $attestation) {
                    if (array_key_exists($attestation["validator_index"], $rewards)) {
                        // what to do with inclusion_delay that can be in data->total_rewards[i]
                        $rewards[$attestation["validator_index"]] += ($attestation["head"] +
                            $attestation["target"] +
                            $attestation["source"]
                        );
                    } else {
                        $rewards[$attestation["validator_index"]] = ($attestation["head"] +
                            $attestation["target"] +
                            $attestation["source"]);
                    }
                }
            }
        }


        // the need to check it still not working this part
        foreach($rewards as $validator => $reward) {
            $events[] = [
                'block' => $epoch,
                'transaction' => "", // number of block or null - for epoch
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', 123223456),
                'address' => $validator,
                'effect' => (string)$reward,
                'failed' => false,
                'extra' => "n", // proposing
                'extra_indexed' => "n"
            ];
            $events[] = [
                'block' => $epoch,
                'transaction' => "",
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', 123223456),
                'address' => "the-void",
                'effect' => (string)($reward * -1), //string
                'failed' => false, // 
                'extra' => "n", //       delete from columns 
                'extra_indexed' => "n" //
            ];
        }

        $this->block_time = "0";
        static::$events_prev = $events;
        static::$epoch_prev = $epoch;
        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance($index)
    {
        return (string)requester_single($this->select_node(),
        endpoint: "eth/v1/beacon/states/head/validators/{$index}",
        timeout: $this->timeout)['data']['balance'];
    }
}
