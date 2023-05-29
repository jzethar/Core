<?php declare(strict_types = 1);


abstract class BeaconAbstractModule extends CoreModule
{
    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra', 'extra_indexed'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public ?string $parent_root = null;

    // Blockchain-specific

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        // if (is_null($this->workchain)) throw new DeveloperError("`workchain` is not set");
    }

    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(), endpoint: 'eth/v1/beacon/headers', timeout: $this->timeout);
        return $this->get_header_slot($result);
    }

    private function get_header_slot($header) {
        if(array_key_exists("data", $header)) {
            $result = $header["data"];
            if(count($result) == 1) {
                $result = $result[0];
                if(array_key_exists("header", $result)) {
                    $result = $result["header"];
                    if(array_key_exists("message", $result)) {
                        $result = $result["message"];
                        if(array_key_exists("slot", $result)) {
                            return (int)$result["slot"];
                        }
                    }
                }
            }
        }
    }

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

    // what to do:
    // 1. Checkout every epoch for changing validator balances - it will be rewards
    // 2. Checkout every slot for withdrawals - it's getting out their "free" money out of stake (but body still staked)

    final public function pre_process_block($block_id)
    {
        $block_times = [];

        $events = [];
        $sort_key = 0;

        $block = requester_single(
            $this->select_node(),
            endpoint: "eth/v1/beacon/blocks/{$this->block_hash}",
            timeout: $this->timeout
        );

        $epoch = (int)(floor((int)$this->block_id / 32));
        $state_root = "";
        $slot = $this->block_id;
        $validator = "";
        $block_root = "";
        $block_root_cur = "";
        $key_tes = 0;
        $slot_attested_prev = 0;

        if(array_key_exists("data", $block)) {
            $block = $block["data"];
            if(array_key_exists("message", $block)) {
                $block = $block["message"];
                // if(array_key_exists("state_root", $block)) {
                //     $state_root = $block["state_root"];
                // }
                if(array_key_exists("body", $block)) {
                    $block = $block["body"];
                    if(array_key_exists("execution_payload", $block)) {
                        $execution_payload = $block["execution_payload"];
                        if(array_key_exists("withdrawals", $execution_payload)) {
                            $withdrawals = $execution_payload["withdrawals"];
                            foreach($withdrawals as $withdrawal) {
                                $address = $withdrawal["address"];
                                $events[] = [
                                    'block' => $slot,
                                    'transaction' => $epoch,
                                    'sort_key' => $key_tes++,
                                    'time' => date('Y-m-d H:i:s', $epoch),
                                    'address' => $address,
                                    'effect' => $withdrawal["amount"],
                                    'failed' => false,
                                    'extra' => $withdrawal["validator_index"],
                                    'extra_indexed' => $block_root_cur
                                ];
                                $events[] = [
                                    'block' => $slot,
                                    'transaction' => $epoch,
                                    'sort_key' => $key_tes++,
                                    'time' => date('Y-m-d H:i:s', $epoch),
                                    'address' => "the-void",
                                    'effect' => "-" . $withdrawal["amount"],
                                    'failed' => false,
                                    'extra' => $withdrawal["validator_index"],
                                    'extra_indexed' => $block_root_cur
                                ];
                            }
                        }
                    }
                }
            }
        }

        $this->block_time = "0";
        // $smm = 0;
        // foreach($events as $event) {
        //     $event["effect"];
        // }

        $this->set_return_events($events);
    }

    private function get_committees($slot) {

        $header = requester_single(
            $this->select_node(),
            endpoint: "eth/v1/beacon/headers?slot={$slot}",
            timeout: $this->timeout
        );

        $state_id = "";
        if(array_key_exists("data", $header)) {
            $result = $header["data"];
            if(count($result) == 1) {
                $result = $result[0];
                if(array_key_exists("header", $result)) {
                    $result = $result["header"];
                    if(array_key_exists("message", $result)) {
                        $result = $result["message"];
                        if(array_key_exists("state_root", $result)) {
                            $state_id = $result["state_root"];
                        }
                    }
                }
            }
        }

        $committees = requester_single(
            $this->select_node(),
            endpoint: "eth/v1/beacon/states/{$state_id}/committees?slot={$slot}",
            timeout: $this->timeout
        );
        if (array_key_exists("data", $committees)) {
            $committees = $committees["data"];
        }
        return $committees;
    } 

    // Getting balances from the node
    public function api_get_balance($address)
    {
        return (string)requester_single($this->select_node(),
            endpoint: "account?account={$address}",
            timeout: $this->timeout)['balance'];
    }
}
