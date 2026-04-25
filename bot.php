<?php
/*
 * IP Refer Telegram Bot
 * Single-file webhook bot with referral system, IP/proxy redeem, and admin panel
 * Database: SQLite (auto-created)
 * Admin: via bot commands (inline keyboard)
 */

// ======================== CONFIG ========================
define('ADMIN_ID', 8572825944);
define('DB_FILE', __DIR__ . '/database.sqlite');

// ======================== DATABASE ========================
function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(DB_FILE);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        initDB($db);
    }
    return $db;
}

function initDB($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT DEFAULT '',
        first_name TEXT DEFAULT '',
        balance INTEGER DEFAULT 0,
        referred_by INTEGER DEFAULT 0,
        referral_rewarded INTEGER DEFAULT 0,
        joined_channels INTEGER DEFAULT 0,
        bonus_given INTEGER DEFAULT 0,
        created_at TEXT DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS proxies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proxy_text TEXT NOT NULL,
        is_redeemed INTEGER DEFAULT 0,
        redeemed_by INTEGER DEFAULT 0,
        redeemed_at TEXT DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS redemptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        mb_amount INTEGER NOT NULL,
        proxy_count INTEGER NOT NULL,
        proxies_text TEXT DEFAULT '',
        created_at TEXT DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS referrals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        referrer_id INTEGER NOT NULL,
        referred_id INTEGER NOT NULL,
        mb_rewarded INTEGER DEFAULT 0,
        created_at TEXT DEFAULT ''
    )");

    // Default settings
    $defaults = [
        'channel1' => '',
        'channel2' => '',
        'join_bonus' => '50',
        'refer_reward' => '100',
        'help_message' => 'Welcome to our bot! Contact admin for help.',
        'bot_token' => '',
        'bot_username' => '',
    ];
    foreach ($defaults as $k => $v) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)");
        $stmt->bindValue(':k', $k);
        $stmt->bindValue(':v', $v);
        $stmt->execute();
    }
}

// ======================== SETTINGS HELPERS ========================
function getSetting($key) {
    $db = getDB();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = :k");
    $stmt->bindValue(':k', $key);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['value'] : '';
}

function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v2");
    $stmt->bindValue(':k', $key);
    $stmt->bindValue(':v', $value);
    $stmt->bindValue(':v2', $value);
    $stmt->execute();
}

// ======================== TELEGRAM API ========================
function getBotToken() {
    return getSetting('bot_token');
}

function apiRequest($method, $params = []) {
    $token = getBotToken();
    if (empty($token)) return null;
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function sendMessage($chatId, $text, $replyMarkup = null, $parseMode = 'HTML') {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
    ];
    if ($replyMarkup) {
        $params['reply_markup'] = $replyMarkup;
    }
    return apiRequest('sendMessage', $params);
}

function editMessage($chatId, $messageId, $text, $replyMarkup = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup) {
        $params['reply_markup'] = $replyMarkup;
    }
    return apiRequest('editMessageText', $params);
}

function answerCallback($callbackId, $text = '', $showAlert = false) {
    return apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $showAlert,
    ]);
}

function sendPhoto($chatId, $photoUrl, $caption = '') {
    return apiRequest('sendPhoto', [
        'chat_id' => $chatId,
        'photo' => $photoUrl,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ]);
}

function checkChannelMember($chatId, $userId) {
    $result = apiRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
    ]);
    if ($result && isset($result['result']['status'])) {
        $status = $result['result']['status'];
        return in_array($status, ['member', 'administrator', 'creator']);
    }
    return false;
}

// ======================== USER HELPERS ========================
function getUser($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :uid");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function createUser($userId, $username, $firstName, $referredBy = 0) {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR IGNORE INTO users (user_id, username, first_name, balance, referred_by, referral_rewarded, joined_channels, bonus_given, created_at) VALUES (:uid, :uname, :fname, 0, :ref, 0, 0, 0, :cat)");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':uname', $username);
    $stmt->bindValue(':fname', $firstName);
    $stmt->bindValue(':ref', $referredBy, SQLITE3_INTEGER);
    $stmt->bindValue(':cat', date('Y-m-d H:i:s'));
    $stmt->execute();
}

function updateBalance($userId, $amount) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET balance = balance + :amt WHERE user_id = :uid");
    $stmt->bindValue(':amt', $amount, SQLITE3_INTEGER);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

function getUserBalance($userId) {
    $user = getUser($userId);
    return $user ? (int)$user['balance'] : 0;
}

// ======================== MAIN KEYBOARD ========================
function mainKeyboard() {
    return [
        'keyboard' => [
            [['text' => '🔗 Refer & Free IP'], ['text' => '💰 MB Balance']],
            [['text' => '🎁 Redeem'], ['text' => '📋 My IP']],
            [['text' => '❓ Help'], ['text' => '👨‍💻 Developer']],
        ],
        'resize_keyboard' => true,
        'is_persistent' => true,
    ];
}

// ======================== FORCE JOIN ========================
function forceJoinMessage($userId) {
    $ch1 = getSetting('channel1');
    $ch2 = getSetting('channel2');

    if (empty($ch1) || empty($ch2)) {
        return null;
    }

    $text = "🔰 <b>Welcome to IP Refer Bot!</b>\n\n";
    $text .= "📢 Please join both channels below to use this bot:\n\n";
    $text .= "After joining, click <b>✅ Verify</b> button.";

    $ch1Display = str_replace('@', '', $ch1);
    $ch2Display = str_replace('@', '', $ch2);

    $buttons = [
        'inline_keyboard' => [
            [
                ['text' => "📢 Channel 1", 'url' => "https://t.me/{$ch1Display}"],
                ['text' => "📢 Channel 2", 'url' => "https://t.me/{$ch2Display}"],
            ],
            [
                ['text' => '✅ Verify', 'callback_data' => 'verify_join'],
            ],
        ],
    ];

    return ['text' => $text, 'buttons' => $buttons];
}

function isUserJoinedChannels($userId) {
    $ch1 = getSetting('channel1');
    $ch2 = getSetting('channel2');

    if (empty($ch1) || empty($ch2)) {
        return true;
    }

    $ch1Id = (strpos($ch1, '@') === 0) ? $ch1 : '@' . $ch1;
    $ch2Id = (strpos($ch2, '@') === 0) ? $ch2 : '@' . $ch2;

    return checkChannelMember($ch1Id, $userId) && checkChannelMember($ch2Id, $userId);
}

// ======================== HANDLERS ========================

function handleStart($chatId, $userId, $username, $firstName, $text) {
    $referredBy = 0;
    if (preg_match('/\/start ref_(\d+)/', $text, $matches)) {
        $refId = (int)$matches[1];
        if ($refId !== $userId) {
            $referredBy = $refId;
        }
    }

    $existingUser = getUser($userId);
    if (!$existingUser) {
        createUser($userId, $username, $firstName, $referredBy);
    }

    $fjMsg = forceJoinMessage($userId);
    if ($fjMsg) {
        sendMessage($chatId, $fjMsg['text'], $fjMsg['buttons']);
    } else {
        $welcomeText = "🔰 <b>Welcome to IP Refer Bot!</b>\n\n";
        $welcomeText .= "Use the menu below to navigate.";
        sendMessage($chatId, $welcomeText, mainKeyboard());
    }
}

function handleVerifyCallback($callbackId, $chatId, $messageId, $userId) {
    if (!isUserJoinedChannels($userId)) {
        answerCallback($callbackId, '❌ Please join both channels first!', true);
        return;
    }

    $db = getDB();
    $user = getUser($userId);

    // Mark as joined
    $stmt = $db->prepare("UPDATE users SET joined_channels = 1 WHERE user_id = :uid");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    // Give join bonus (first time only)
    if ($user && (int)$user['bonus_given'] === 0) {
        $joinBonus = (int)getSetting('join_bonus');
        if ($joinBonus > 0) {
            updateBalance($userId, $joinBonus);
            $stmt2 = $db->prepare("UPDATE users SET bonus_given = 1 WHERE user_id = :uid");
            $stmt2->bindValue(':uid', $userId, SQLITE3_INTEGER);
            $stmt2->execute();
        }
    }

    // Process referral reward
    if ($user && (int)$user['referred_by'] > 0 && (int)$user['referral_rewarded'] === 0) {
        $referrerId = (int)$user['referred_by'];
        $referrer = getUser($referrerId);
        if ($referrer) {
            $referReward = (int)getSetting('refer_reward');
            if ($referReward > 0) {
                updateBalance($referrerId, $referReward);

                // Record referral
                $stmt3 = $db->prepare("INSERT INTO referrals (referrer_id, referred_id, mb_rewarded, created_at) VALUES (:rid, :rfid, :mb, :cat)");
                $stmt3->bindValue(':rid', $referrerId, SQLITE3_INTEGER);
                $stmt3->bindValue(':rfid', $userId, SQLITE3_INTEGER);
                $stmt3->bindValue(':mb', $referReward, SQLITE3_INTEGER);
                $stmt3->bindValue(':cat', date('Y-m-d H:i:s'));
                $stmt3->execute();

                // Mark referral as rewarded
                $stmt4 = $db->prepare("UPDATE users SET referral_rewarded = 1 WHERE user_id = :uid");
                $stmt4->bindValue(':uid', $userId, SQLITE3_INTEGER);
                $stmt4->execute();

                // Notify referrer
                sendMessage($referrerId, "🎉 <b>Referral Bonus!</b>\n\n✅ Your referral joined the channels!\n💰 +{$referReward} MB added to your balance.");
            }
        }
    }

    $joinBonus = (int)getSetting('join_bonus');
    $successText = "✅ <b>Verification Successful!</b>\n\n";
    $successText .= "🎉 Welcome! You've been verified.\n";
    if ($joinBonus > 0 && $user && (int)$user['bonus_given'] === 0) {
        $successText .= "💰 +{$joinBonus} MB joining bonus added!\n";
    }
    $successText .= "\nUse the menu below to navigate.";

    editMessage($chatId, $messageId, $successText);
    sendMessage($chatId, "📱 <b>Main Menu</b>\n\nChoose an option:", mainKeyboard());
}

function handleReferButton($chatId, $userId) {
    $botUsername = getSetting('bot_username');
    $referReward = getSetting('refer_reward');

    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM referrals WHERE referrer_id = :uid");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $totalReferrals = $row ? (int)$row['cnt'] : 0;

    $referLink = "https://t.me/{$botUsername}?start=ref_{$userId}";

    $text = "🔗 <b>Refer & Free IP</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "📎 <b>Your Referral Link:</b>\n";
    $text .= "<code>{$referLink}</code>\n\n";
    $text .= "💰 <b>Per Referral Reward:</b> {$referReward} MB\n";
    $text .= "👥 <b>Total Referrals:</b> {$totalReferrals}\n";
    $text .= "━━━━━━━━━━━━━━━━━\n\n";
    $text .= "📌 <i>Share your link with friends. When they join both channels and verify, you'll get {$referReward} MB!</i>";

    sendMessage($chatId, $text);
}

function handleBalanceButton($chatId, $userId) {
    $user = getUser($userId);
    if (!$user) return;

    $name = htmlspecialchars($user['first_name']);
    $uname = $user['username'] ? '@' . htmlspecialchars($user['username']) : 'N/A';
    $balance = (int)$user['balance'];

    $text = "💰 <b>MB Balance</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "👤 <b>Name:</b> {$name}\n";
    $text .= "🆔 <b>Telegram ID:</b> <code>{$userId}</code>\n";
    $text .= "📛 <b>Username:</b> {$uname}\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "💰 <b>Balance:</b> {$balance} MB\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";

    sendMessage($chatId, $text);
}

function handleRedeemButton($chatId, $userId) {
    $balance = getUserBalance($userId);

    $text = "🎁 <b>Redeem IP/Proxy</b>\n\n";
    $text .= "💰 <b>Your Balance:</b> {$balance} MB\n\n";
    $text .= "Select a package to redeem:\n";
    $text .= "<i>(Every 200 MB = 1 Proxy)</i>";

    $buttons = [
        'inline_keyboard' => [
            [
                ['text' => '📦 200 MB', 'callback_data' => 'redeem_200'],
                ['text' => '📦 600 MB', 'callback_data' => 'redeem_600'],
            ],
            [
                ['text' => '📦 1000 MB', 'callback_data' => 'redeem_1000'],
                ['text' => '📦 1600 MB', 'callback_data' => 'redeem_1600'],
            ],
            [
                ['text' => '📦 2600 MB', 'callback_data' => 'redeem_2600'],
                ['text' => '📦 5000 MB', 'callback_data' => 'redeem_5000'],
            ],
            [
                ['text' => '📦 10000 MB', 'callback_data' => 'redeem_10000'],
            ],
        ],
    ];

    sendMessage($chatId, $text, $buttons);
}

function handleRedeemCallback($callbackId, $chatId, $messageId, $userId, $mbAmount) {
    $balance = getUserBalance($userId);

    if ($balance < $mbAmount) {
        answerCallback($callbackId, "❌ Insufficient balance! You need {$mbAmount} MB but have {$balance} MB.", true);
        return;
    }

    $proxyCount = intdiv($mbAmount, 200);
    $db = getDB();

    // Get available proxies
    $stmt = $db->prepare("SELECT * FROM proxies WHERE is_redeemed = 0 LIMIT :lim");
    $stmt->bindValue(':lim', $proxyCount, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $proxies = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $proxies[] = $row;
    }

    if (count($proxies) < $proxyCount) {
        answerCallback($callbackId, "❌ Not enough proxies available! Need {$proxyCount}, available: " . count($proxies), true);
        return;
    }

    // Deduct balance
    $stmt2 = $db->prepare("UPDATE users SET balance = balance - :amt WHERE user_id = :uid");
    $stmt2->bindValue(':amt', $mbAmount, SQLITE3_INTEGER);
    $stmt2->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt2->execute();

    // Mark proxies as redeemed
    $proxyTexts = [];
    foreach ($proxies as $proxy) {
        $stmt3 = $db->prepare("UPDATE proxies SET is_redeemed = 1, redeemed_by = :uid, redeemed_at = :rat WHERE id = :pid");
        $stmt3->bindValue(':uid', $userId, SQLITE3_INTEGER);
        $stmt3->bindValue(':rat', date('Y-m-d H:i:s'));
        $stmt3->bindValue(':pid', $proxy['id'], SQLITE3_INTEGER);
        $stmt3->execute();
        $proxyTexts[] = $proxy['proxy_text'];
    }

    // Record redemption
    $stmt4 = $db->prepare("INSERT INTO redemptions (user_id, mb_amount, proxy_count, proxies_text, created_at) VALUES (:uid, :mb, :pc, :pt, :cat)");
    $stmt4->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt4->bindValue(':mb', $mbAmount, SQLITE3_INTEGER);
    $stmt4->bindValue(':pc', $proxyCount, SQLITE3_INTEGER);
    $stmt4->bindValue(':pt', json_encode($proxyTexts));
    $stmt4->bindValue(':cat', date('Y-m-d H:i:s'));
    $stmt4->execute();

    $newBalance = getUserBalance($userId);

    $text = "✅ <b>Redeem Successful!</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "📦 <b>Package:</b> {$mbAmount} MB\n";
    $text .= "🔢 <b>Proxies:</b> {$proxyCount}\n";
    $text .= "💰 <b>Remaining Balance:</b> {$newBalance} MB\n";
    $text .= "━━━━━━━━━━━━━━━━━\n\n";
    $text .= "🔐 <b>Your Proxy/IP:</b>\n\n";

    foreach ($proxyTexts as $i => $pt) {
        $num = $i + 1;
        $text .= "{$num}. <code>{$pt}</code>\n";
    }

    $text .= "\n📅 <b>Date:</b> " . date('d M Y, h:i A');

    editMessage($chatId, $messageId, $text);
}

function handleMyIPButton($chatId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM redemptions WHERE user_id = :uid ORDER BY created_at DESC");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $redemptions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $redemptions[] = $row;
    }

    if (empty($redemptions)) {
        sendMessage($chatId, "📋 <b>My IP List</b>\n\n❌ You haven't redeemed any IP/proxy yet.\n\nUse 🎁 <b>Redeem</b> to get your first proxy!");
        return;
    }

    $text = "📋 <b>My IP List</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";

    foreach ($redemptions as $idx => $r) {
        $num = $idx + 1;
        $proxies = json_decode($r['proxies_text'], true);
        $text .= "\n📦 <b>#{$num} - {$r['mb_amount']} MB ({$r['proxy_count']} Proxy)</b>\n";
        $text .= "📅 {$r['created_at']}\n";
        if (is_array($proxies)) {
            foreach ($proxies as $p) {
                $text .= "  ➜ <code>{$p}</code>\n";
            }
        }
        $text .= "━━━━━━━━━━━━━━━━━\n";

        // Telegram message limit
        if (strlen($text) > 3500) {
            sendMessage($chatId, $text);
            $text = "";
        }
    }

    if (!empty($text)) {
        sendMessage($chatId, $text);
    }
}

function handleHelpButton($chatId) {
    $helpMsg = getSetting('help_message');
    $text = "❓ <b>Help</b>\n\n{$helpMsg}";
    sendMessage($chatId, $text);
}

function handleDeveloperButton($chatId) {
    $text = "👨‍💻 <b>Developer</b>\n\n";
    $text .= "🤖 Bot, App Make করতে চাইলে Contact করো।\n";
    $text .= "Professional Telegram Bot & Web App Development.";

    $buttons = [
        'inline_keyboard' => [
            [
                ['text' => '📩 Contact Now', 'url' => 'https://t.me/onlyfahimxd'],
            ],
        ],
    ];

    sendMessage($chatId, $text, $buttons);
}

// ======================== ADMIN HANDLERS ========================

// State tracking for admin multi-step actions
function getAdminState($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = :k");
    $stmt->bindValue(':k', "admin_state_{$userId}");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['value'] : '';
}

function setAdminState($userId, $state) {
    setSetting("admin_state_{$userId}", $state);
}

function clearAdminState($userId) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM settings WHERE key = :k");
    $stmt->bindValue(':k', "admin_state_{$userId}");
    $stmt->execute();
}

function handleAdmin($chatId, $userId) {
    if ($userId != ADMIN_ID) {
        sendMessage($chatId, "❌ You are not authorized.");
        return;
    }

    $db = getDB();

    // Stats
    $totalUsers = $db->querySingle("SELECT COUNT(*) FROM users");
    $totalSold = $db->querySingle("SELECT COUNT(*) FROM proxies WHERE is_redeemed = 1");
    $totalReferrals = $db->querySingle("SELECT COUNT(*) FROM referrals");
    $totalProxies = $db->querySingle("SELECT COUNT(*) FROM proxies WHERE is_redeemed = 0");

    $text = "🔐 <b>Admin Panel</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "👥 <b>Total Users:</b> {$totalUsers}\n";
    $text .= "🔄 <b>Total Referrals:</b> {$totalReferrals}\n";
    $text .= "📦 <b>IP Sold:</b> {$totalSold}\n";
    $text .= "🗃 <b>Available Proxies:</b> {$totalProxies}\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";

    $buttons = [
        'inline_keyboard' => [
            [
                ['text' => '📊 Dashboard', 'callback_data' => 'admin_dashboard'],
                ['text' => '📢 Set Channel', 'callback_data' => 'admin_set_channel'],
            ],
            [
                ['text' => '🎁 Join Bonus Set', 'callback_data' => 'admin_join_bonus'],
                ['text' => '🔗 Refer Reward Set', 'callback_data' => 'admin_refer_reward'],
            ],
            [
                ['text' => '❓ Help Msg Set', 'callback_data' => 'admin_help_msg'],
                ['text' => '📣 Broadcast Send', 'callback_data' => 'admin_broadcast'],
            ],
            [
                ['text' => '➕ IP Account Add', 'callback_data' => 'admin_add_ip'],
                ['text' => '⚙️ Settings', 'callback_data' => 'admin_settings'],
            ],
            [
                ['text' => '🛒 Sell IP (Redeemed)', 'callback_data' => 'admin_sell_ip'],
            ],
        ],
    ];

    sendMessage($chatId, $text, $buttons);
}

function handleAdminCallback($callbackId, $chatId, $messageId, $userId, $data) {
    if ($userId != ADMIN_ID) {
        answerCallback($callbackId, '❌ Unauthorized!', true);
        return;
    }

    switch ($data) {
        case 'admin_dashboard':
            handleAdminDashboard($callbackId, $chatId, $messageId);
            break;
        case 'admin_set_channel':
            answerCallback($callbackId);
            setAdminState($userId, 'waiting_channel1');
            sendMessage($chatId, "📢 <b>Set Channels</b>\n\nSend <b>Channel 1</b> username (e.g., <code>@mychannel</code> or <code>mychannel</code>):");
            break;
        case 'admin_join_bonus':
            answerCallback($callbackId);
            $current = getSetting('join_bonus');
            setAdminState($userId, 'waiting_join_bonus');
            sendMessage($chatId, "🎁 <b>Set Join Bonus</b>\n\nCurrent: {$current} MB\n\nSend new join bonus amount (MB):");
            break;
        case 'admin_refer_reward':
            answerCallback($callbackId);
            $current = getSetting('refer_reward');
            setAdminState($userId, 'waiting_refer_reward');
            sendMessage($chatId, "🔗 <b>Set Refer Reward</b>\n\nCurrent: {$current} MB\n\nSend new refer reward amount (MB):");
            break;
        case 'admin_help_msg':
            answerCallback($callbackId);
            $current = getSetting('help_message');
            setAdminState($userId, 'waiting_help_msg');
            sendMessage($chatId, "❓ <b>Set Help Message</b>\n\nCurrent:\n{$current}\n\nSend new help message:");
            break;
        case 'admin_broadcast':
            answerCallback($callbackId);
            setAdminState($userId, 'waiting_broadcast_photo');
            sendMessage($chatId, "📣 <b>Broadcast Message</b>\n\nSend the <b>image URL</b> (or send <code>skip</code> for text only):");
            break;
        case 'admin_add_ip':
            handleAdminAddIP($callbackId, $chatId, $messageId);
            break;
        case 'admin_settings':
            answerCallback($callbackId);
            $botToken = getSetting('bot_token');
            $botUname = getSetting('bot_username');
            $tokenDisplay = $botToken ? (substr($botToken, 0, 10) . '...') : 'Not Set';
            $unameDisplay = $botUname ?: 'Not Set';

            $text = "⚙️ <b>Bot Settings</b>\n\n";
            $text .= "🔑 <b>Bot Token:</b> {$tokenDisplay}\n";
            $text .= "📛 <b>Bot Username:</b> {$unameDisplay}\n\n";
            $text .= "What do you want to update?";

            $buttons = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔑 Set Bot Token', 'callback_data' => 'admin_set_token'],
                        ['text' => '📛 Set Bot Username', 'callback_data' => 'admin_set_username'],
                    ],
                    [
                        ['text' => '🔙 Back', 'callback_data' => 'admin_back'],
                    ],
                ],
            ];
            sendMessage($chatId, $text, $buttons);
            break;
        case 'admin_set_token':
            answerCallback($callbackId);
            setAdminState($userId, 'waiting_bot_token');
            sendMessage($chatId, "🔑 <b>Set Bot Token</b>\n\nSend new bot token:");
            break;
        case 'admin_set_username':
            answerCallback($callbackId);
            setAdminState($userId, 'waiting_bot_username');
            sendMessage($chatId, "📛 <b>Set Bot Username</b>\n\nSend new bot username (without @):");
            break;
        case 'admin_sell_ip':
            handleAdminSellIP($callbackId, $chatId, $messageId);
            break;
        case 'admin_back':
            answerCallback($callbackId);
            handleAdmin($chatId, $userId);
            break;
    }
}

function handleAdminDashboard($callbackId, $chatId, $messageId) {
    answerCallback($callbackId);
    $db = getDB();

    $totalUsers = $db->querySingle("SELECT COUNT(*) FROM users");
    $totalSold = $db->querySingle("SELECT COUNT(*) FROM proxies WHERE is_redeemed = 1");
    $totalReferrals = $db->querySingle("SELECT COUNT(*) FROM referrals");
    $totalProxies = $db->querySingle("SELECT COUNT(*) FROM proxies WHERE is_redeemed = 0");
    $totalMBSold = $db->querySingle("SELECT COALESCE(SUM(mb_amount), 0) FROM redemptions");
    $totalMBReferred = $db->querySingle("SELECT COALESCE(SUM(mb_rewarded), 0) FROM referrals");

    $text = "📊 <b>Dashboard</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "👥 <b>Total Users:</b> {$totalUsers}\n";
    $text .= "📦 <b>IP Sold:</b> {$totalSold}\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "🔄 <b>Total Referrals:</b> {$totalReferrals}\n";
    $text .= "💰 <b>Total MB Sold:</b> {$totalMBSold} MB\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";
    $text .= "🗃 <b>Available Proxies:</b> {$totalProxies}\n";
    $text .= "🎁 <b>MB Given (Referrals):</b> {$totalMBReferred} MB\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";

    $buttons = [
        'inline_keyboard' => [
            [['text' => '🔙 Back to Admin', 'callback_data' => 'admin_back']],
        ],
    ];

    sendMessage($chatId, $text, $buttons);
}

function handleAdminAddIP($callbackId, $chatId, $messageId) {
    answerCallback($callbackId);
    $db = getDB();

    // Show existing proxies
    $available = $db->querySingle("SELECT COUNT(*) FROM proxies WHERE is_redeemed = 0");
    $total = $db->querySingle("SELECT COUNT(*) FROM proxies");

    $text = "➕ <b>IP Account Add</b>\n\n";
    $text .= "📊 Total: {$total} | Available: {$available}\n\n";

    // Show last 10 available proxies
    $stmt = $db->prepare("SELECT * FROM proxies WHERE is_redeemed = 0 ORDER BY id DESC LIMIT 10");
    $result = $stmt->execute();
    $proxies = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $proxies[] = $row;
    }

    if (!empty($proxies)) {
        $text .= "📋 <b>Recent Available IPs:</b>\n";
        foreach ($proxies as $p) {
            $text .= "  ➜ <code>{$p['proxy_text']}</code>\n";
        }
    }

    $text .= "\n━━━━━━━━━━━━━━━━━\n";
    $text .= "📝 Send proxy/IP to add (one per line for bulk add):";

    setAdminState(ADMIN_ID, 'waiting_add_ip');

    $buttons = [
        'inline_keyboard' => [
            [['text' => '🔙 Back to Admin', 'callback_data' => 'admin_back']],
        ],
    ];

    sendMessage($chatId, $text, $buttons);
}

function handleAdminSellIP($callbackId, $chatId, $messageId) {
    answerCallback($callbackId);
    $db = getDB();

    $stmt = $db->prepare("SELECT p.*, u.first_name, u.username FROM proxies p LEFT JOIN users u ON p.redeemed_by = u.user_id WHERE p.is_redeemed = 1 ORDER BY p.redeemed_at DESC LIMIT 20");
    $result = $stmt->execute();

    $text = "🛒 <b>Sold/Redeemed IPs</b>\n\n";
    $text .= "━━━━━━━━━━━━━━━━━\n";

    $count = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        $name = $row['first_name'] ?? 'Unknown';
        $uname = $row['username'] ? '@' . $row['username'] : '';
        $text .= "\n{$count}. <code>{$row['proxy_text']}</code>\n";
        $text .= "   👤 {$name} {$uname} (ID: {$row['redeemed_by']})\n";
        $text .= "   📅 {$row['redeemed_at']}\n";
        $text .= "━━━━━━━━━━━━━━━━━\n";

        if (strlen($text) > 3500) {
            sendMessage($chatId, $text);
            $text = "";
        }
    }

    if ($count === 0) {
        $text .= "\n❌ No redeemed IPs yet.\n";
    }

    $buttons = [
        'inline_keyboard' => [
            [['text' => '🔙 Back to Admin', 'callback_data' => 'admin_back']],
        ],
    ];

    if (!empty($text)) {
        sendMessage($chatId, $text, $buttons);
    }
}

function handleAdminTextInput($chatId, $userId, $text) {
    $state = getAdminState($userId);
    if (empty($state)) return false;

    switch ($state) {
        case 'waiting_channel1':
            $channel = trim(str_replace('@', '', $text));
            setSetting('channel1', $channel);
            setAdminState($userId, 'waiting_channel2');
            sendMessage($chatId, "✅ Channel 1 set to: <b>@{$channel}</b>\n\nNow send <b>Channel 2</b> username:");
            return true;

        case 'waiting_channel2':
            $channel = trim(str_replace('@', '', $text));
            setSetting('channel2', $channel);
            clearAdminState($userId);
            $ch1 = getSetting('channel1');
            sendMessage($chatId, "✅ <b>Channels Updated!</b>\n\n📢 Channel 1: @{$ch1}\n📢 Channel 2: @{$channel}\n\n/admin - Back to admin panel");
            return true;

        case 'waiting_join_bonus':
            $amount = (int)trim($text);
            if ($amount < 0) {
                sendMessage($chatId, "❌ Invalid amount. Send a positive number:");
                return true;
            }
            setSetting('join_bonus', (string)$amount);
            clearAdminState($userId);
            sendMessage($chatId, "✅ <b>Join Bonus Updated!</b>\n\n🎁 New bonus: {$amount} MB\n\n/admin - Back to admin panel");
            return true;

        case 'waiting_refer_reward':
            $amount = (int)trim($text);
            if ($amount < 0) {
                sendMessage($chatId, "❌ Invalid amount. Send a positive number:");
                return true;
            }
            setSetting('refer_reward', (string)$amount);
            clearAdminState($userId);
            sendMessage($chatId, "✅ <b>Refer Reward Updated!</b>\n\n🔗 New reward: {$amount} MB\n\n/admin - Back to admin panel");
            return true;

        case 'waiting_help_msg':
            setSetting('help_message', $text);
            clearAdminState($userId);
            sendMessage($chatId, "✅ <b>Help Message Updated!</b>\n\n/admin - Back to admin panel");
            return true;

        case 'waiting_broadcast_photo':
            $input = trim($text);
            if (strtolower($input) === 'skip') {
                setAdminState($userId, 'waiting_broadcast_text_only');
                sendMessage($chatId, "📝 Now send the <b>broadcast text message</b>:");
            } else {
                setSetting('broadcast_photo', $input);
                setAdminState($userId, 'waiting_broadcast_text');
                sendMessage($chatId, "✅ Image URL saved.\n\n📝 Now send the <b>broadcast text/caption</b>:");
            }
            return true;

        case 'waiting_broadcast_text':
            $photoUrl = getSetting('broadcast_photo');
            clearAdminState($userId);
            sendBroadcast($chatId, $text, $photoUrl);
            return true;

        case 'waiting_broadcast_text_only':
            clearAdminState($userId);
            sendBroadcast($chatId, $text, '');
            return true;

        case 'waiting_add_ip':
            $lines = array_filter(array_map('trim', explode("\n", $text)));
            $db = getDB();
            $added = 0;
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $stmt = $db->prepare("INSERT INTO proxies (proxy_text, is_redeemed) VALUES (:pt, 0)");
                    $stmt->bindValue(':pt', $line);
                    $stmt->execute();
                    $added++;
                }
            }
            clearAdminState($userId);
            $available = $db->querySingle("SELECT COUNT(*) FROM proxies WHERE is_redeemed = 0");
            sendMessage($chatId, "✅ <b>{$added} IP(s) Added!</b>\n\n🗃 Total Available: {$available}\n\n/admin - Back to admin panel");
            return true;

        case 'waiting_bot_token':
            setSetting('bot_token', trim($text));
            clearAdminState($userId);
            sendMessage($chatId, "✅ <b>Bot Token Updated!</b>\n\n⚠️ Make sure to set webhook again if token changed.\n\n/admin - Back to admin panel");
            return true;

        case 'waiting_bot_username':
            $uname = trim(str_replace('@', '', $text));
            setSetting('bot_username', $uname);
            clearAdminState($userId);
            sendMessage($chatId, "✅ <b>Bot Username Updated!</b>\n\n📛 Username: @{$uname}\n\n/admin - Back to admin panel");
            return true;
    }

    return false;
}

function sendBroadcast($adminChatId, $text, $photoUrl = '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id FROM users");
    $result = $stmt->execute();

    $success = 0;
    $failed = 0;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $uid = $row['user_id'];
        try {
            if (!empty($photoUrl)) {
                $res = sendPhoto($uid, $photoUrl, $text);
            } else {
                $res = sendMessage($uid, $text);
            }
            if ($res && isset($res['ok']) && $res['ok']) {
                $success++;
            } else {
                $failed++;
            }
        } catch (Exception $e) {
            $failed++;
        }
        usleep(50000); // 50ms delay to avoid rate limit
    }

    sendMessage($adminChatId, "📣 <b>Broadcast Complete!</b>\n\n✅ Sent: {$success}\n❌ Failed: {$failed}");
}

// ======================== WEBHOOK SETUP HELPER ========================
function handleSetWebhook($chatId, $userId) {
    if ($userId != ADMIN_ID) return;

    $token = getBotToken();
    if (empty($token)) {
        sendMessage($chatId, "❌ Bot token not set. Use /admin → Settings to set it first.");
        return;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/bot.php';
    $webhookUrl = "{$protocol}://{$host}{$scriptName}";

    $result = apiRequest('setWebhook', ['url' => $webhookUrl]);

    if ($result && isset($result['ok']) && $result['ok']) {
        sendMessage($chatId, "✅ <b>Webhook Set!</b>\n\nURL: <code>{$webhookUrl}</code>");
    } else {
        $desc = $result['description'] ?? 'Unknown error';
        sendMessage($chatId, "❌ Failed to set webhook.\n\nError: {$desc}\n\nTry manually:\n<code>https://api.telegram.org/bot{$token}/setWebhook?url={$webhookUrl}</code>");
    }
}

// ======================== MAIN WEBHOOK HANDLER ========================
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    // If accessed via browser, show setup info
    echo "<h2>IP Refer Bot</h2>";
    echo "<p>This is a Telegram webhook bot. Set up via /admin in Telegram.</p>";

    // Check if database exists
    if (file_exists(DB_FILE)) {
        echo "<p>Database: ✅ Connected</p>";
    } else {
        echo "<p>Database: ⏳ Will be created on first request</p>";
    }
    exit;
}

// Initialize DB
getDB();

// Handle Message
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $firstName = $message['from']['first_name'] ?? '';
    $text = $message['text'] ?? '';

    // Admin text input handler (priority)
    if ($userId == ADMIN_ID && handleAdminTextInput($chatId, $userId, $text)) {
        exit;
    }

    // Commands
    if (strpos($text, '/start') === 0) {
        handleStart($chatId, $userId, $username, $firstName, $text);
    } elseif ($text === '/admin') {
        handleAdmin($chatId, $userId);
    } elseif ($text === '/setwebhook') {
        handleSetWebhook($chatId, $userId);
    }
    // Keyboard buttons
    elseif ($text === '🔗 Refer & Free IP') {
        if (!isUserJoinedChannels($userId)) {
            $fjMsg = forceJoinMessage($userId);
            if ($fjMsg) sendMessage($chatId, $fjMsg['text'], $fjMsg['buttons']);
        } else {
            handleReferButton($chatId, $userId);
        }
    } elseif ($text === '💰 MB Balance') {
        if (!isUserJoinedChannels($userId)) {
            $fjMsg = forceJoinMessage($userId);
            if ($fjMsg) sendMessage($chatId, $fjMsg['text'], $fjMsg['buttons']);
        } else {
            handleBalanceButton($chatId, $userId);
        }
    } elseif ($text === '🎁 Redeem') {
        if (!isUserJoinedChannels($userId)) {
            $fjMsg = forceJoinMessage($userId);
            if ($fjMsg) sendMessage($chatId, $fjMsg['text'], $fjMsg['buttons']);
        } else {
            handleRedeemButton($chatId, $userId);
        }
    } elseif ($text === '📋 My IP') {
        if (!isUserJoinedChannels($userId)) {
            $fjMsg = forceJoinMessage($userId);
            if ($fjMsg) sendMessage($chatId, $fjMsg['text'], $fjMsg['buttons']);
        } else {
            handleMyIPButton($chatId, $userId);
        }
    } elseif ($text === '❓ Help') {
        handleHelpButton($chatId);
    } elseif ($text === '👨‍💻 Developer') {
        handleDeveloperButton($chatId);
    }
}

// Handle Callback Query
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $callbackId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $userId = $callback['from']['id'];
    $data = $callback['data'];

    if ($data === 'verify_join') {
        handleVerifyCallback($callbackId, $chatId, $messageId, $userId);
    } elseif (preg_match('/^redeem_(\d+)$/', $data, $matches)) {
        $mbAmount = (int)$matches[1];
        handleRedeemCallback($callbackId, $chatId, $messageId, $userId, $mbAmount);
    } elseif (strpos($data, 'admin_') === 0) {
        handleAdminCallback($callbackId, $chatId, $messageId, $userId, $data);
    }
}
