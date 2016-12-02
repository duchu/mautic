<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\ChannelBundle;

/**
 * Class ChannelEvents.
 */
final class ChannelEvents
{
    /**
     * The mautic.add_channel event registers communication channels.
     *
     * The event listener receives a Mautic\ChannelBundle\Event\ChannelEvent instance.
     *
     * @var string
     */
    const ADD_CHANNEL = 'mautic.add_channel';

    /**
     * The mautic.channel_broadcast event is dispatched by the mautic:send:broadcast command to process communication to pending contacts.
     *
     * The event listener receives a Mautic\ChannelBundle\Event\ChannelBroadcastEvent instance.
     *
     * @var string
     */
    const CHANNEL_BROADCAST = 'mautic.channel_broadcast';
}