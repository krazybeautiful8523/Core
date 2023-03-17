<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes BEP-1155 (a.k.a. ERC-1155) MT transfers in BNB. It requires either a geth or
 *  an Erigon node to run.  */

final class BNBBEP1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bnb';
        $this->module = 'bnb-bep-1155';
        $this->is_main = false;
        $this->first_block_date = '2020-08-29';
        $this->first_block_id = 0;
    }
}
