<?php

namespace App\Enums;

enum ChannelType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Gotify = 'gotify';
    case Ntfy = 'ntfy';
    case Apprise = 'apprise';
    case Webhook = 'webhook';
}
