<?php declare(strict_types = 1);


final class BeaconValidatorRewardsModule extends BeaconValidatorRewardsAbstractModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'beacon';
        $this->module = 'beacon-main';
        $this->is_main = true;
        $this->currency = 'ETH';
        $this->currency_details = ['name' => 'Beacon', 'symbol' => 'ETH', 'decimals' => 9, 'description' => null];
        $this->first_block_date = '2015-07-30';
        $this->first_block_id = 0;
    }
}