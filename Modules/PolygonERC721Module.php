<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes ERC-721 NFT transfers in Polygon. It requires either a geth or
 *  an Erigon node to run.  */

final class PolygonERC721Module extends EVMERC721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon';
        $this->module = 'polygon-erc-721';
        $this->is_main = false;
        $this->first_block_date = '2020-05-30';
        $this->first_block_id = 0;
    }
}
