<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 token transfers in Energi. */

final class EnergiERC1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'energi';
        $this->module = 'energi-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2020-03-10';
        $this->first_block_id = 0;
    }
}
