<?php
// PHP Errors setup
ini_set('display_errors', 0);
ini_set('allow_url_fopen', 1);

define('BOT_TOKEN', '8608938779:AAFJo5dBtwHBFgfezWoPVd9xi_LdjnFA9Wg');
define('ADMIN_ID', '6130075298');
define('BOT_NAME', 'Dara OSINT Bot');
define('BOT_USERNAME', 'DaraSearchBot');
define('OWNER_USERNAME', 'fatsuen');
define('BUY_CREDITS_URL', 'https://t.me/fatsuen');
define('SUPPORT_URL', 'https://t.me/fatsuen');

// 🌐xnx.com
define('DATA_URL', 'https://raw.githubusercontent.com/username/repository/main/data.json');

define('DEFAULT_DAILY_CREDITS', 5);
define('RATE_LIMIT_SECONDS', 3);
define('DATA_FILE', __DIR__ . '/clone_users.json');
define('KEYS_FILE', __DIR__ . '/clone_keys.json');
define('STATS_FILE', __DIR__ . '/clone_stats.json');
define('ADMINS_FILE', __DIR__ . '/clone_admins.json');
define('MAINTENANCE_FILE', __DIR__ . '/clone_maint.json');

define('BOT_HEADER', "🔍 *" . BOT_NAME . "*\n━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
define('BOT_FOOTER', "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📢 *Owner:* @" . OWNER_USERNAME . "\n💳 *Buy Credits:* [" . OWNER_USERNAME . "](" . BUY_CREDITS_URL . ")\n🆘 *Support:* [" . OWNER_USERNAME . "](" . SUPPORT_URL . ")");

// -------------------------------------------------------------
// 1. लिंक से लाइव डेटा खींचने और सर्च करने का फ़ंक्शन
// -------------------------------------------------------------
function searchFromLinkData($module, $query) {
    $cleanQuery = trim($query);

    // 1. लिंक से डेटा फ़ैच करें
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, DATA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return [
            "status"  => "error",
            "message" => "Unable to connect to data link."
        ];
    }

    $database = json_decode($response, true);
    if (!$database || !is_array($database)) {
        return [
            "status"  => "error",
            "message" => "Data on link is not valid JSON."
        ];
    }

    // 2. फ़ैच किए गए डेटा में खोजें
    if (isset($database[$cleanQuery])) {
        return [
            "status"  => "success",
            "module"  => strtoupper($module),
            "query"   => $cleanQuery,
            "details" => $database[$cleanQuery]
        ];
    }

    // Array के अंदर सर्च करने के लिए
    foreach ($database as $key => $val) {
        if (is_array($val) && (in_array($cleanQuery, $val, true) || strpos(json_encode($val), $cleanQuery) !== false)) {
            return [
                "status"  => "success",
                "module"  => strtoupper($module),
                "query"   => $cleanQuery,
                "details" => $val
            ];
        }
    }

    return [
        "status"  => "not_found",
        "module"  => strtoupper($module),
        "query"   => $cleanQuery,
        "message" => "No record found for: " . $cleanQuery
    ];
}

// -------------------------------------------------------------
// 2. Telegram Webhook Handler
// -------------------------------------------------------------
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId   = (string)$callback['message']['chat']['id'];
    $data     = $callback['data'];

    if (strpos($data, 'set_module|') === 0) {
        $module = substr($data, 11);
        setUserModule($chatId, $module);
        answerCallbackQuery($callback['id'], "Selected " . strtoupper($module) . " module!", false);
        sendMessageJSON(
            $chatId,
            "🔍 *Selected Module:* `" . strtoupper($module) . "`\n\nPlease enter the target query to search:",
            ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cancel_input']]]],
            'Markdown'
        );
        exit;
    }

    if ($data === 'cancel_input') {
        setUserState($chatId, null);
        answerCallbackQuery($callback['id'], "Input cancelled", false);
        sendMessageJSON($chatId, "❌ Action cancelled. Choose an option from the menu below:", mainKeyboard(), 'Markdown');
        exit;
    }
    exit;
}

if (!isset($update['message'])) exit;

$msg       = $update['message'];
$chatId    = (string)$msg['chat']['id'];
$username  = $msg['from']['username'] ?? null;
$firstName = $msg['from']['first_name'] ?? 'User';

if (($msg['chat']['type'] ?? 'private') !== 'private') exit;

if (isset($msg['document']) || isset($msg['photo']) || isset($msg['audio']) || isset($msg['video'])) {
    sendMessageJSON($chatId, "❌ *File uploads are disabled.* Please enter text search queries only.", null, 'Markdown');
    exit;
}

$text = trim($msg['text'] ?? '');
if (empty($text)) exit;

$user = getUser($chatId);
if (!$user) {
    $user = [
        'user_id'       => $chatId,
        'username'      => $username,
        'first_name'    => $firstName,
        'credits'       => DEFAULT_DAILY_CREDITS,
        'last_request'  => 0,
        'total_lookups' => 0,
        'module'        => 'number',
        'joined_at'     => date('Y-m-d H:i:s'),
        'last_login'    => date('Y-m-d')
    ];
    saveUser($chatId, $user);
    recordNewUser();
} else {
    if (($user['last_login'] ?? '') !== date('Y-m-d')) {
        $user['last_login'] = date('Y-m-d');
        if (!($user['vip'] ?? false)) {
            $user['credits'] = DEFAULT_DAILY_CREDITS;
        }
        saveUser($chatId, $user);
    }
}

if (isInMaintenance() && !isAdmin($chatId)) {
    sendMessageJSON($chatId, "🚧 *Bot Maintenance*\n\nThe bot is currently undergoing maintenance. Please try again later!", null, 'Markdown');
    exit;
}

// Menu Buttons
if ($text === '📱 Number Info') {
    setUserModule($chatId, 'number');
    sendMessageJSON($chatId, "📱 *Number Info Selected*\n\nPlease enter the phone number to search:", null, 'Markdown');
    exit;
}
if ($text === '🪪 Aadhaar Info') {
    setUserModule($chatId, 'aadhaar');
    sendMessageJSON($chatId, "🪪 *Aadhaar Info Selected*\n\nPlease enter the search query:", null, 'Markdown');
    exit;
}
if ($text === '👨‍👩‍👧‍👦 Family Info') {
    setUserModule($chatId, 'family');
    sendMessageJSON($chatId, "👨‍👩‍👧‍👦 *Family Info Selected*\n\nPlease enter the search query:", null, 'Markdown');
    exit;
}
if ($text === '📁 Upload DB') {
    setUserModule($chatId, 'upload_db');
    sendMessageJSON($chatId, "📁 *Upload DB Selected*\n\nPlease enter your DB search query:", null, 'Markdown');
    exit;
}
if ($text === '👤 My Account' || $text === '/myinfo' || $text === '/profile') {
    $credits = ($user['vip'] ?? false) ? "♾️ Unlimited (VIP)" : "{$user['credits']} Credits";
    $profileMsg = BOT_HEADER .
                  "👤 *ACCOUNT INFORMATION*\n\n" .
                  "🆔 *User ID:* `{$chatId}`\n" .
                  "👤 *Name:* " . htmlspecialchars($firstName) . "\n" .
                  "💳 *Balance:* {$credits}\n" .
                  "🔍 *Total Searches:* {$user['total_lookups']}\n" .
                  "📅 *Daily Credits Reset:* Midnight GMT\n" .
                  BOT_FOOTER;
    sendMessageJSON($chatId, $profileMsg, mainKeyboard(), 'Markdown');
    exit;
}
if ($text === '🛒 Buy Credits' || $text === '/buy') {
    $buyMsg = BOT_HEADER .
              "🛒 *PURCHASE CREDITS & VIP*\n\n" .
              "Get instant access to unlimited lookups and premium features!\n\n" .
              "💵 *Pricing Plan:*\n" .
              "• 50 Credits — 50 INR\n" .
              "• 150 Credits — 120 INR\n" .
              "• 👑 VIP (Unlimited 30 Days) — 299 INR\n\n" .
              "📩 Contact owner to buy keys:\n" .
              "👤 Owner: [" . OWNER_USERNAME . "](" . BUY_CREDITS_URL . ")\n\n" .
              "🔑 After purchase, redeem key using:\n`/redeem YOUR_KEY_HERE`\n" .
              BOT_FOOTER;
    sendMessageJSON($chatId, $buyMsg, mainKeyboard(), 'Markdown');
    exit;
}

if (strpos($text, '/start') === 0) {
    $welcomeMsg = BOT_HEADER .
                 "👋 *Welcome " . htmlspecialchars($firstName) . "!*\n\n" .
                 "This bot provides search services for various modules.\n\n" .
                 "📊 *Your Balance:* `{$user['credits']}` free credits available.\n\n" .
                 "👇 *Choose an option from the menu buttons below:*";
    sendMessageJSON($chatId, $welcomeMsg, mainKeyboard(), 'Markdown');
    exit;
}

if ($text === '/help') {
    $helpMsg = BOT_HEADER .
               "📖 *BOT COMMANDS & MODULES*\n\n" .
               "• `/num <query>` — Number Info\n" .
               "• `/aadhaar <query>` — Aadhaar Info\n" .
               "• `/family <query>` — Family Info\n" .
               "• `/uploaddb <query>` — Search Uploaded DB\n" .
               "• `/redeem <key>` — Redeem License Key\n" .
               "• `/myinfo` — Check Balance & Stats\n" .
               BOT_FOOTER;
    sendMessageJSON($chatId, $helpMsg, mainKeyboard(), 'Markdown');
    exit;
}

if (strpos($text, '/redeem') === 0 || strpos($text, '/key') === 0) {
    $parts = explode(' ', $text);
    $keyInput = trim($parts[1] ?? '');
    if (empty($keyInput)) {
        sendMessageJSON($chatId, "⚠️ *Usage:* `/redeem YOUR_KEY_HERE`", null, 'Markdown');
        exit;
    }

    $keys = loadKeys();
    if (!isset($keys[$keyInput]) || ($keys[$keyInput]['used'] ?? false)) {
        sendMessageJSON($chatId, "❌ *Invalid or already used key!*", null, 'Markdown');
        exit;
    }

    $keyData = $keys[$keyInput];
    $addedCredits = (int)($keyData['credits'] ?? 0);
    $isVipKey = ($keyData['is_vip'] ?? false);

    if ($isVipKey) {
        $user['vip'] = true;
    } else {
        $user['credits'] = ($user['credits'] ?? 0) + $addedCredits;
    }

    $keys[$keyInput]['used'] = true;
    $keys[$keyInput]['used_by'] = $chatId;
    $keys[$keyInput]['used_at'] = date('Y-m-d H:i:s');
    saveKeys($keys);
    saveUser($chatId, $user);

    $msgText = $isVipKey ? "🎉 *VIP Status Activated!* You now have unlimited searches." : "✅ *Key Redeemed!* Added +{$addedCredits} credits to your balance.";
    sendMessageJSON($chatId, $msgText, mainKeyboard(), 'Markdown');
    exit;
}

$cmdModules = [
    '/num' => 'number',
    '/number' => 'number',
    '/aadhaar' => 'aadhaar',
    '/aadhar' => 'aadhaar',
    '/family' => 'family',
    '/uploaddb' => 'upload_db',
    '/db' => 'upload_db'
];

foreach ($cmdModules as $cmd => $modName) {
    if (strpos($text, $cmd) === 0) {
        $parts = explode(' ', $text, 2);
        $queryInput = trim($parts[1] ?? '');
        if (empty($queryInput)) {
            sendMessageJSON($chatId, "⚠️ *Usage:* `{$cmd} <value>`", null, 'Markdown');
            exit;
        }
        processLookupRequest($chatId, $modName, $queryInput, $user);
        exit;
    }
}

$currentModule = $user['module'] ?? 'number';
processLookupRequest($chatId, $currentModule, $text, $user);
exit;

// -------------------------------------------------------------
// 3. Request Processor
// -------------------------------------------------------------
function processLookupRequest($chatId, $module, $query, $user) {
    $lastReq = $user['last_request'] ?? 0;
    if (time() - $lastReq < RATE_LIMIT_SECONDS) {
        $wait = RATE_LIMIT_SECONDS - (time() - $lastReq);
        sendMessageJSON($chatId, "⏳ *Cooldown Active!* Please wait {$wait} seconds before searching again.", null, 'Markdown');
        return;
    }

    $isVip = $user['vip'] ?? false;
    if (!$isVip && ($user['credits'] ?? 0) <= 0) {
        sendMessageJSON(
            $chatId,
            "❌ *Out of Credits!*\n\nYou have 0 free credits left. Buy key from owner or wait for daily midnight reset.\n\n💳 *Buy Keys:* [" . OWNER_USERNAME . "](" . BUY_CREDITS_URL . ")",
            mainKeyboard(),
            'Markdown'
        );
        return;
    }

    if (!$isVip) {
        $user['credits']--;
    }
    $user['last_request'] = time();
    $user['total_lookups'] = ($user['total_lookups'] ?? 0) + 1;
    saveUser($chatId, $user);
    recordLookup();

    $statusMsg = sendMessageJSON($chatId, "🔍 *Searching " . strtoupper($module) . " database for:* `{$query}`...", null, 'Markdown');
    $msgId = $statusMsg['result']['message_id'] ?? null;

    // लिंक से लाइव डेटा फ़ैच करके सर्च करें
    $apiResponse = searchFromLinkData($module, $query);

    $formattedResult = formatLookupResponse($module, $query, $apiResponse);
    $finalText = BOT_HEADER . $formattedResult . BOT_FOOTER;

    if ($msgId) {
        editMessageTextJSON($chatId, $msgId, $finalText, null, 'Markdown');
    } else {
        sendMessageJSON($chatId, $finalText, null, 'Markdown');
    }
}

function formatLookupResponse($module, $query, $data) {
    $modName = strtoupper($module);
    $out = "📌 *MODULE:* `{$modName}`\n" .
           "🎯 *QUERY:* `{$query}`\n\n";

    $jsonStr = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $out .= "```json\n" . $jsonStr . "\n```";
    return $out;
}

function mainKeyboard() {
    return [
        'keyboard' => [
            [['text' => '📱 Number Info'], ['text' => '🪪 Aadhaar Info']],
            [['text' => '👨‍👩‍👧‍👦 Family Info'], ['text' => '📁 Upload DB']],
            [['text' => '👤 My Account'], ['text' => '🛒 Buy Credits']]
        ],
        'resize_keyboard' => true,
        'persistent' => true
    ];
}

function setUserModule($chatId, $module) {
    $u = getUser($chatId);
    if ($u) {
        $u['module'] = $module;
        saveUser($chatId, $u);
    }
}

function setUserState($chatId, $state) {
    $u = getUser($chatId);
    if ($u) {
        $u['state'] = $state;
        saveUser($chatId, $u);
    }
}

function getUser($chatId) {
    $users = loadAllUsers();
    return $users[$chatId] ?? null;
}

function saveUser($chatId, $user) {
    $users = loadAllUsers();
    $users[$chatId] = $user;
    @file_put_contents(DATA_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
}

function loadAllUsers() {
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(@file_get_contents(DATA_FILE), true) ?? [];
}

function loadKeys() {
    if (!file_exists(KEYS_FILE)) return [];
    return json_decode(@file_get_contents(KEYS_FILE), true) ?? [];
}

function saveKeys($keys) {
    @file_put_contents(KEYS_FILE, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
}

function loadStats() {
    if (!file_exists(STATS_FILE)) return ['total_lookups' => 0];
    return json_decode(@file_get_contents(STATS_FILE), true) ?? ['total_lookups' => 0];
}

function saveStats($stats) {
    @file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}

function recordLookup() {
    $stats = loadStats();
    $stats['total_lookups'] = ($stats['total_lookups'] ?? 0) + 1;
    saveStats($stats);
}

function recordNewUser() {
    $stats = loadStats();
    $stats['total_users'] = ($stats['total_users'] ?? 0) + 1;
    saveStats($stats);
}

function isInMaintenance() {
    if (!file_exists(MAINTENANCE_FILE)) return false;
    $d = json_decode(@file_get_contents(MAINTENANCE_FILE), true);
    return ($d['on'] ?? false) === true;
}

function isAdmin($chatId) {
    if ((string)$chatId === (string)ADMIN_ID) return true;
    if (!file_exists(ADMINS_FILE)) return false;
    $admins = json_decode(@file_get_contents(ADMINS_FILE), true) ?? [];
    return in_array((string)$chatId, $admins);
}

function sendMessageJSON($chatId, $text, $replyMarkup = null, $parseMode = null) {
    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
    if ($parseMode)   $params['parse_mode']   = $parseMode;
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function editMessageTextJSON($chatId, $messageId, $text, $replyMarkup = null, $parseMode = null) {
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
    if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
    if ($parseMode)   $params['parse_mode']   = $parseMode;
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/editMessageText');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function answerCallbackQuery($callbackId, $text = null, $showAlert = false) {
    $params = ['callback_query_id' => $callbackId];
    if ($text)      $params['text']       = $text;
    if ($showAlert) $params['show_alert'] = true;
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/answerCallbackQuery');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
