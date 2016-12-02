<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\ChannelBundle\Event;

use Mautic\CoreBundle\Event\CommonEvent;

/**
 * Class ChannelEvent.
 */
class ChannelEvent extends CommonEvent
{
    /**
     * @var array
     */
    protected $channels = [];

    /**
     * @var array
     */
    protected $channelsBySupportedFeature = [];

    /**
     * Returns the channel.
     *
     * @return string
     */
    public function getChannels()
    {
        return $this->channels;
    }

    /**
     * Adds a submit action to the list of available actions.
     *
     * @param string $channel a unique identifier; it is recommended that it be namespaced if there are multiple entities in a channel  i.e. something.something
     * @param array  $config  Should be keyed by the feature it supports that contains an array of feature configuration options. i.e.
     *                        $config = [
     *                        MessageModel::CHANNEL_FEATURE => [
     *                        'lookupFormType'       => (optional) Form type class/alias for the channel lookup list,
     *                        'propertiesFormType'   => (optional) Form type class/alias for the channel properties if a lookup list is not used,
     *                        'goalsSupported'       => (optional) array of campaign decisions applicable as successful conversions for this channel
     *                        'channelTemplate'      => (optional) template to inject UI/DOM into the bottom of the channel's tab
     *                        'formTheme'           => (optional) theme directory for custom form types
     *
     *                          ]
     *                       ]
     *
     * @return $this
     */
    public function addChannel($channel, array $config = [])
    {
        $this->channels[$channel] = $config;

        foreach ($config as $feature => $featureConfig) {
            $this->channelsBySupportedFeature[$feature][$channel] = $featureConfig;
        }

        return $this;
    }

    /**
     * @param $feature
     *
     * @return array
     */
    public function getFeatureChannels($feature)
    {
        return (isset($this->channelsBySupportedFeature[$feature])) ? $this->channelsBySupportedFeature[$feature] : [];
    }
}