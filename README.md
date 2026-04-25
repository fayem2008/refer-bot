# IP Refer Telegram Bot

A single-file PHP Telegram bot with referral system, MB balance, IP/proxy redeem, and admin panel.

## Features

- **Force Join**: Users must join 2 channels before using the bot
- **Referral System**: Earn MB for each referral (configurable)
- **MB Balance**: Track earned MB
- **Redeem**: Exchange MB for IP/proxy accounts (200 MB = 1 proxy)
- **Admin Panel**: Full bot management via Telegram commands
- **Broadcast**: Send messages (with images) to all users
- **SQLite Database**: No external DB needed

## Requirements

- PHP 7.4+ with SQLite3 extension
- HTTPS hosting (required for Telegram webhooks)
- cURL extension

## Setup

1. **Get Bot Token**: Create a bot via [@BotFather](https://t.me/BotFather)

2. **Upload**: Upload `bot.php` to your hosting

3. **Set Webhook**: Open in browser:
   ```
   https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://yourdomain.com/bot.php
   ```

4. **Configure Admin**: 
   - Send `/admin` to the bot (only works for ADMIN_ID set in code)
   - Go to Settings → Set Bot Token
   - Go to Settings → Set Bot Username
   - Set channels, bonuses, and add proxies

## Admin Commands

- `/admin` - Open admin panel
- `/setwebhook` - Auto-set webhook URL

## File Structure

```
bot.php          ← Main bot file (everything in one file)
database.sqlite  ← Auto-created SQLite database
```

## Admin Panel Features

| Feature | Description |
|---------|-------------|
| Dashboard | View stats (users, referrals, IP sold) |
| Set Channel | Configure 2 force-join channels |
| Join Bonus Set | Set MB bonus for new users |
| Refer Reward Set | Set MB per referral |
| Help Msg Set | Set help message |
| Broadcast Send | Send image+text to all users |
| IP Account Add | Add proxy/IP accounts |
| Settings | Set bot token & username |
| Sell IP | View all redeemed IPs |

## Redeem Packages

| Package | Proxies |
|---------|---------|
| 200 MB | 1 proxy |
| 600 MB | 3 proxies |
| 1000 MB | 5 proxies |
| 1600 MB | 8 proxies |
| 2600 MB | 13 proxies |
| 5000 MB | 25 proxies |
| 10000 MB | 50 proxies |

## Developer

Contact: [@onlyfahimxd](https://t.me/onlyfahimxd)
