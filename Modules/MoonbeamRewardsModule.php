<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes moonbeam staking events, it requires sidecar API instance and a moonbeam node */

final class MoonbeamRewardsModule extends MoonbeamLikeRewardsModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'moonbeam';
        $this->module = 'moonbeam-rewards';
        $this->complements = 'moonbeam-main';
        $this->is_main = false;
        $this->first_block_date = '2021-12-18';
    }
}
