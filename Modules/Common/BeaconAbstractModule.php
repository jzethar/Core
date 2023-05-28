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

    final public function pre_process_block($block_id)
    {
        // if ($block_id === 0) // Block #0 is there, but the node doesn't return data for it
        // {
        //     $this->block_time = date('Y-m-d H:i:s', 0);
        //     $this->set_return_events([]);
        //     return;
        // }

        $block_times = [];

        $events = [];
        $sort_key = 0;

        $block = requester_single(
            $this->select_node(),
            endpoint: "eth/v1/beacon/blocks/{$this->block_hash}",
            timeout: $this->timeout
        );
        // TODO: this should be rewritten with requester_multi()

        // ['block' => [1, 4, 'INT'],                       --- EPOCH ---
        //  'transaction' => [2, -1, 'BYTEA'],              --- state root --- 
        //  'sort_key' => [3, 4, 'INT'],                    --- SLOT ---
        //  'time' => [4, 8, 'TIMESTAMP'], 
        //  'address' => [5, -1, 'BYTEA'],                  --- Validator ---
        //  'currency' => [6, -1, 'BYTEA'],                          
        //  'effect' => [7, -1, 'NUMERIC'],                 --- 0 - no, 1 - for --- 
        //  'failed' => [8, 1, 'BOOLEAN'],                  --- if block not passed ---
        //  'extra' => [9, -1, 'BYTEA'],                    --- block root (voting for) --- 
        //  'extra_indexed' => [10, -1, 'BYTEA'],           --- current voting block root  --- 

        $epoch = (int)(floor((int)$this->block_id / 32));
        $state_root = "";
        $slot = $this->block_id;
        $validator = "";
        $block_root = "";
        $block_root_cur = "";
        $key_tes = 0;
        $slot_attested_prev = 0;


        // /eth/v1/beacon/states/:state_id/committees?slot=6504800

        // change logic for another endpoint /eth/v1/beacon/blocks/:block_id/attestations 
        // /eth/v1/validator/duties/proposer/:epoch - here to get proposer of the block

        if(array_key_exists("data", $block)) {
            $block = $block["data"];
            if(array_key_exists("message", $block)) {
                $block = $block["message"];
                if(array_key_exists("state_root", $block)) {
                    $state_root = $block["state_root"];
                }
                if(array_key_exists("body", $block)) {
                    $block = $block["body"];
                    if(array_key_exists("attestations", $block)) {
                        $attestations = $block["attestations"];
                        foreach($attestations as $attestation) {
                            if(array_key_exists("data", $attestation)) {
                                if(array_key_exists("index", $attestation["data"]) && array_key_exists("slot", $attestation["data"])) {
                                    $index = (int)$attestation["data"]["index"];
                                    $slot_attested = (int)$attestation["data"]["slot"];
                                    if ($slot_attested != $slot_attested_prev) {
                                        $committees = $this->get_committees($slot_attested);
                                        $slot_attested_prev = $slot_attested;
                                    }
                                    $validators = $committees[$index];
                                }
                                if(array_key_exists("beacon_block_root", $attestation["data"])){
                                    $block_root_cur = $attestation["data"]["beacon_block_root"];
                                }
                                if(array_key_exists("aggregation_bits", $attestation)){
                                    $aggregation_bits = MustParseHex($attestation["aggregation_bits"]);
                                }
                                for($i = 0; $i < BitLen($aggregation_bits); $i++) {
                                    $validator = $validators["validators"][$i];
                                    $events[] = [
                                        'block' => $slot,
                                        'transaction' => $state_root,
                                        'sort_key' => $key_tes++,
                                        'time' => date('Y-m-d H:i:s', $epoch),
                                        'address' => $validator,
                                        'effect' => (BitAt($aggregation_bits, $i) ? "1" : "0"),
                                        'failed' => false,
                                        'extra' => $block_root,
                                        'extra_indexed' => $block_root_cur
                                    ];
                                }
                            }

                        }
                    }
                }
            }
        }

        $this->block_time = "0";

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
