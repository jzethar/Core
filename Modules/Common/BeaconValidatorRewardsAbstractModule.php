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
        throw new RequesterEmptyResponseException("get_header_slot(fields are changed)");
    }


    // merge all hashes of blocks in epoch 
    public function ensure_block($epoch, $break_on_first = false)
    {
        $hashes = [];

        $block_start = $epoch * 32;
        $block_end = ($epoch * 32) + 31;

        foreach ($this->nodes as $node) {
            for ($i = $block_start; $i <= $block_end; $i++) {
                $multi_curl[] = requester_multi_prepare(
                    $node,
                    endpoint: "eth/v1/beacon/headers/{$i}",
                    timeout: $this->timeout
                );

                if ($break_on_first)
                    break;
            }
            try {
                $curl_results = requester_multi($multi_curl, limit: 10, timeout: $this->timeout, valid_codes: [200, 404]);
            } catch (RequesterException $e) {
                throw new RequesterException("ensure_block(epoch: {$epoch}): no connection, previously: " . $e->getMessage());
            }
            foreach($curl_results as $result) {
                $hash_result = requester_multi_process($result);
                $root = "";
                $slot = "";
                if (array_key_exists("data", $hash_result)) {
                    $hash_result = $hash_result["data"];
                    if (array_key_exists("root", $hash_result)) {
                        $root = $hash_result["root"];
                    }
                    if (array_key_exists("header", $hash_result)) {
                        if (array_key_exists("message", $hash_result["header"])) {
                            if (array_key_exists("slot", $hash_result["header"]["message"])) {
                                $slot = $hash_result["header"]["message"]["slot"];
                            } else {
                                throw new RequesterEmptyResponseException("get_header_slot(fields are changed)");
                            }
                        } else {
                            throw new RequesterEmptyResponseException("get_header_slot(fields are changed)");
                        }
                    } else {
                        throw new RequesterEmptyResponseException("get_header_slot(fields are changed)");
                    }
                    $hashes_res[$slot] = $root;
                }
            }
            ksort($hashes_res);
            foreach($hashes_res as $slot => $hash) {
                $hash .= $hash;
            }
            $hashes[] = $hash;
            unset($hashes_res);
            unset($hash);
            unset($multi_curl);
        }

        for($i = 0; $i < count($hashes); $i++) {
            if($i + 1 < count($hashes)) {
                if($hashes[$i] != $hashes[$i+1]) {
                    throw new ConsensusException("ensure_block(block_id: {$epoch}): no consensus"); 
                }
            }
        }
        $this->block_hash = $hashes[0];
        $this->block_id = $epoch;
    }


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
            10,
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
                        $rewards[$rw["validator_index"]] = bcadd($rw["reward"], $rewards[$rw["validator_index"]]);
                    } else {
                        $rewards[$rw["validator_index"]] = $rw["reward"];
                    }
                }
            }
        }

        $key_tes = 0;
        $last_slot = max(array_keys($slots));

        foreach ($rewards_slots as $validator => $info) {
            $extra = "p";
            if ($slots[$info[0]] == 0) {
                $extra = "om";
            }
            $effect = $info[1];
            if($effect == "") {
                $effect = "0";
            }
            $events[] = [
                'block' => $epoch,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$info[0]]),
                'address' => $validator,
                'effect' => $effect,
                'extra' => $extra
            ];
            $events[] = [
                'block' => $epoch,
                'transaction' => $info[0],
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$info[0]]),
                'address' => "the-void",
                'effect' => "-" . $effect,
                'extra' => $extra
            ];
        }

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
                        $rewards[$attestation["validator_index"]] = bcadd(
                            bcadd(
                                bcadd($attestation["head"], $attestation["target"]),
                                $attestation["source"]
                            ),
                            $rewards[$attestation["validator_index"]]
                        );
                    } else {
                        $rewards[$attestation["validator_index"]] =
                        bcadd(
                            bcadd($attestation["head"], $attestation["target"]),
                            $attestation["source"]
                        );
                    }
                }
            }
        }
        

        foreach($rewards as $validator => $reward) {
            $events[] = [
                'block' => $epoch,
                'transaction' => "",
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$last_slot]),
                'address' => $validator,
                'effect' => (string)$reward,
                'extra' => "a"
            ];
            $events[] = [
                'block' => $epoch,
                'transaction' => "",
                'sort_key' => $key_tes++,
                'time' => date('Y-m-d H:i:s', $slots[$last_slot]),
                'address' => "the-void",
                'effect' => (string)(bcmul($reward, "-1")),
                'extra' => "a"
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