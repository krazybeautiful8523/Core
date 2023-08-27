<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes attestations happening on the Beacon Chain.
 *  It requires a Prysm-like node to run. This is WIP as it doesn't process pre-ALTAIR_FORK_EPOCH epochs.  */

abstract class BeaconChainLikeAttestationsModule extends CoreModule
{
    use BeaconChainLikeTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::None;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::None;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = false;

    public ?bool $ignore_sum_of_all_effects = true; // Essentially, all events here always come in pair with `the-void`, but we don't
    // put that into the database to save space.

    public string $block_entity_name = 'epoch';
    public string $transaction_entity_name = 'slot';
    public string $address_entity_name = 'validator';

    //

    public array $chain_config = [];

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (!isset($this->chain_config['ALTAIR_FORK_EPOCH'])) throw new DeveloperError("`ALTAIR_FORK_EPOCH` is not set");
        if (!isset($this->chain_config['BELLATRIX_FORK_EPOCH'])) throw new DeveloperError("`BELLATRIX_FORK_EPOCH` is not set");
        if (!isset($this->chain_config['SLOT_PER_EPOCH'])) throw new DeveloperError("`SLOT_PER_EPOCH` is not set");
        if (!isset($this->chain_config['DELAY'])) throw new DeveloperError("`DELAY` is not set");
    }

    final public function pre_process_block($block) // $block here is an epoch number
    {
        $events = [];
        $rewards = [];
        $rq_committees = [];
        $rq_committees_data = [];
        $rq_slot_time = [];
        $rewards_slots = [];        // [validator] -> [slot, reward]
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
            $slot_data[] = requester_multi_process($slot);

        foreach ($slot_data as $slot_info)
        {
            if (isset($slot_info['code']) && $slot_info['code'] === '404')
                continue;
            elseif (isset($slot_info['code']))
                throw new ModuleError('Unexpected response code');

            $slot_id = (string)$slot_info['data']['message']['slot'];

            if (isset($slot_info['data']['message']['body']['execution_payload']))
                $timestamp = (int)$slot_info['data']['message']['body']['execution_payload']['timestamp'];
            else
                $timestamp = 0;

            $slots[$slot_id] = $timestamp;
        }

        if ($block < $this->chain_config['BELLATRIX_FORK_EPOCH'])
        {
            $last_slot = max(array_keys($slots));

            $last_slot = requester_single(
                $this->select_node(),
                endpoint: "eth/v1/beacon/blocks/{$last_slot}",
                timeout: $this->timeout,
                result_in: 'data',
            );

            $block_hash = $last_slot['message']['body']['eth1_data']['block_hash'];
            $execution_layers = envm($this->module, 'EXECUTION_LAYER_NODES');
            $execution_layer = $execution_layers[array_rand($execution_layers)];

            $last_block_time = requester_single(
                $execution_layer,
                params: ['jsonrpc' => '2.0', 'method' => 'eth_getBlockByHash', 'params' => [$block_hash, false], 'id' => 0],
                timeout: $this->timeout,
                result_in: 'result'
            );

            $block_time = hexdec($last_block_time['timestamp']);
            $slot_keys = array_keys($slots);

            foreach($slot_keys as $slot)
                if ($slots[$slot] !== null)
                    $slots[$slot] = $block_time;

            $this->block_time = date('Y-m-d H:i:s', hexdec($last_block_time['timestamp']));
        }
        else
        {
            $this_slot_times = array_reverse($slots);

            foreach ($this_slot_times as $time)
            {
                if (!is_null($time))
                {
                    $this->block_time = date('Y-m-d H:i:s', $time);
                    break;
                }
            }
        }

        if ($block >= $this->chain_config['ALTAIR_FORK_EPOCH'])
        {
            foreach ($slots as $slot => $tm)
                $rq_committees[] = requester_multi_prepare(
                    $this->select_node(),
                    endpoint: "eth/v1/beacon/rewards/sync_committee/{$slot}",
                    params: '[]',
                    no_json_encode: true
                );

            $rq_committees_multi = requester_multi(
                $rq_committees,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout,
                valid_codes: [200, 404]
            );
            foreach ($rq_committees_multi as $v)
                $rq_committees_data[] = requester_multi_process($v);

            foreach ($rq_committees_data as $slot_rewards) 
            {
                if (isset($slot_rewards['code']) && $slot_rewards['code'] === '404')
                    continue;
                elseif (isset($slot_rewards['code']))
                    throw new ModuleError('Unexpected response code');

                $slot_rewards = $slot_rewards['data'];

                foreach ($slot_rewards as $rw) 
                {
                    if (isset($rewards[$rw['validator_index']]))
                        $rewards[$rw['validator_index']] = bcadd($rw['reward'], $rewards[$rw['validator_index']]);
                    else
                        $rewards[$rw['validator_index']] = $rw['reward'];
                }
            }
        }

        $key_tes = 0;

        if ($block >= $this->chain_config['ALTAIR_FORK_EPOCH'] - 1)
        {
            $attestations = requester_single(
                $this->select_node(),
                endpoint: "eth/v1/beacon/rewards/attestations/{$block}",
                params: '[]',
                no_json_encode: true,
                timeout: 1800,
                result_in: 'data',
                valid_codes: [200]
            );

            foreach ($attestations['total_rewards'] as $attestation) 
            {
                if (isset($rewards[$attestation['validator_index']])) 
                {
                    $rewards[$attestation['validator_index']] =
                    bcadd(
                        bcadd(
                            bcadd(
                                $attestation['head'],
                                $attestation['target']
                            ),
                            $attestation['source']
                        ),
                        $rewards[$attestation['validator_index']]
                    );
                } 
                else 
                {
                    $rewards[$attestation['validator_index']] =
                    bcadd(
                        bcadd(
                            $attestation['head'],
                            $attestation['target']
                        ),
                        $attestation['source']
                    );
                }
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
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => (string)$validator,
                    'effect' => $reward,
                ];
            }
            else
            {
                $events[] = [
                    'block' => $block,
                    'sort_key' => $key_tes++,
                    'time' => $this->block_time,
                    'address' => (string)$validator,
                    'effect' => $reward,
                ];
            }
        }

        $this->set_return_events($events);
    }
}
