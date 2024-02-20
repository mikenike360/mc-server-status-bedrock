<?php
/**
 * Initializes scheduled events for updating Minecraft server data.
 */
function setup_minecraft_cron_job()
{
    if (!wp_next_scheduled('update_minecraft_data')) {
        wp_schedule_event(time(), 'every_ten_minutes', 'update_minecraft_data');
    }
}

add_action('init', 'setup_minecraft_cron_job');

/**
 * Handles the scheduled event to update Minecraft server data.
 */
function update_minecraft_server_data()
{
    retrieveData('mc.marc.tv', 25565); // Replace with your server details
}

add_action('update_minecraft_data', 'update_minecraft_server_data');

/**
 * Retrieves Minecraft server data and updates cached information.
 */
function retrieveData($hostname, $port = 25565)
{
    // Generate the unique cache key for this server
    $serverDataKey = get_server_cache_key($hostname, $port, 'server_data_');
    $playerDataKey = get_server_cache_key($hostname, $port, 'player_data_');

    $data = new MSI\MinecraftData($hostname, $port);
    $isOnline = $data->IsOnline ?? false;

    if ($isOnline) {
        // Update and cache server and player data using the salted keys
        $serverData = [
            'IsOnline' => $isOnline,
            'Motd' => $data->Motd ?? 'N/A',
            'ServerVersion' => $data->ServerVersion ?? 'N/A',
            'PlayersMax' => $data->PlayersMax ?? 0,
            'PlayersOnline' => $data->PlayersOnline ?? 0,
            'timestamp' => time()
        ];
        update_option($serverDataKey, serialize($serverData));
        updatePlayerData($data->Players ?? [], $playerDataKey);
    } else {
        // Retrieve cached server data if available
        $serverData = unserialize(get_option($serverDataKey, '')) ?: [
            'IsOnline' => false,
            'Motd' => 'N/A',
            'ServerVersion' => 'N/A',
            'PlayersMax' => 0,
            'PlayersOnline' => 0,
        ];
    }

    return renderServerData($serverData, $isOnline ? $data->Players : [], $hostname, $port);

}

/**
 * Updates or initializes the player data including their last seen time.
 */
function updatePlayerData($currentPlayers, $playerDataKey)
{
    $savedPlayers = get_option($playerDataKey, []);
    if (!is_array($savedPlayers)) {
        $savedPlayers = [];
    }

    $currentPlayerIds = array_column($currentPlayers, 'id');
    $now = time();

    foreach ($currentPlayers as $player) {
        $savedPlayers[$player['id']] = [
            'name' => $player['name'],
            'lastSeen' => $now,
        ];
    }

    foreach ($savedPlayers as $id => $player) {
        if (!in_array($id, $currentPlayerIds) && (!isset($player['lastSeen']) || $player['lastSeen'] == $now)) {
            $savedPlayers[$id]['lastSeen'] = $now;
        }
    }

    update_option($playerDataKey, $savedPlayers);
}

/**
 * Renders the server data including player information.
 */
function renderServerData($serverData, $currentPlayers, $hostname, $port)
{

    $wpTimezone = wp_timezone();

    // Generate the unique cache key for player data of this server
    $playerDataKey = get_server_cache_key($hostname, $port, 'player_data_');

    // Use the unique key to retrieve player data for the current server
    $savedPlayers = get_option($playerDataKey, []);
    if (!is_array($savedPlayers)) {
        $savedPlayers = [];
    }

    // Determine currently online players and update the max players ever seen if necessary
    $onlinePlayerIds = array_column($currentPlayers, 'id');
    $currentOnlineCount = count($onlinePlayerIds);
    $maxPlayersEverSeen = max($currentOnlineCount, get_option('minecraft_max_players_ever_seen', 0));
    update_option('minecraft_max_players_ever_seen', $maxPlayersEverSeen);

    // Separate players into online and offline for sorting
    $onlinePlayers = [];
    $offlinePlayers = [];
    foreach ($savedPlayers as $id => $player) {
        if (in_array($id, $onlinePlayerIds)) {
            $onlinePlayers[$id] = $player;
        } else {
            $offlinePlayers[$id] = $player;
        }
    }

    // Sort offline players by "last seen" in descending order
    uasort($offlinePlayers, function ($a, $b) {
        return $b['lastSeen'] <=> $a['lastSeen'];
    });

    // Server metadata output
    $output = "<figure class='wp-block-table is-style-stripes'><table class='minecraftserverinfo " . ($serverData['IsOnline'] ? "isonline" : "") . "'>";
    $output .= "<tr><td><strong>" . __('Status', 'minecraft-server-info') . "</strong></td><td class='status'>" . ($serverData['IsOnline'] ? 'Online' : 'Offline') . "</td></tr>";
    $output .= "<tr><td><strong>" . __('Address', 'minecraft-server-info') . "</strong></td><td>" . $hostname . "</td></tr>";
    $output .= "<tr><td><strong>" . __('MOTD', 'minecraft-server-info') . "</strong></td><td>{$serverData['Motd']}</td></tr>";
    $output .= "<tr><td><strong>" . __('Version', 'minecraft-server-info') . "</strong></td><td>{$serverData['ServerVersion']}</td></tr>";
    // Dynamically display the current and maximum players
    $output .= "</table></figure>";

    // Player table with dynamic online count
    $output .= "<figure class='wp-block-table is-style-stripes'><table class='minecraftserverinfo players'>";
    $output .= "<thead><tr><th colspan='2'><strong>" . __('Players', 'minecraft-server-info') . " <span class='text-muted'>($currentOnlineCount/$maxPlayersEverSeen " . __('online', 'minecraft-server-info') . ")</span></strong></th></tr></thead>";

    // First, list online players
    foreach ($onlinePlayers as $id => $player) {
        $output .= formatPlayerRow($id, $player, true, $wpTimezone);
    }
    // Then, list offline players (now sorted and correctly handled)
    foreach ($offlinePlayers as $id => $player) {
        // Ensure we're passing the correct structure and values to formatPlayerRow
        $output .= formatPlayerRow($id, $player, false, $wpTimezone);
    }

    $output .= "</table></figure>";
    return $output;
}

/**
 * Helper function to format a player row.
 */
function formatPlayerRow($id, $player, $isOnline, $wpTimezone)
{
    $avatarURL = "https://mc-heads.net/avatar/{$id}";
    $playerName = $player['name'];
    $lastSeenFormat = $isOnline ? "<span class='playeronline'>Online</span>" : sprintf(
        /* translators: %s: human-readable time difference */
        __('Last Seen: %s ago', 'minecraft-server-info'),
        human_time_diff($player['lastSeen'], current_time('timestamp'))
    );

    $row = "<tr>";
    $row .= "<td>{$playerName} <img src='{$avatarURL}' alt='{$playerName}'s Avatar' width='18' height='18'></td>";
    $row .= "<td>{$lastSeenFormat}</td>";
    $row .= "</tr>";

    return $row;
}

/**
 * Renders a Table of Contents block for a post.
 */
function render_status($attributes)
{
    $html = retrieveData($attributes['address'], $attributes['port']); // Replace with your server details
    return $html;
}

function add_every_ten_minutes_schedule($schedules)
{
    $schedules['every_ten_minutes'] = [
        'interval' => 60, // 10 minutes in seconds
        'display' => __('Every 60 Seconds')
    ];
    return $schedules;
}
add_filter('cron_schedules', 'add_every_ten_minutes_schedule');

function get_server_cache_key($hostname, $port, $prefix = '')
{
    $sanitizedHostname = preg_replace('#^https?://#', '', rtrim($hostname, '/'));
    $cacheKey = $prefix . 'minecraft_data_' . md5($sanitizedHostname . '_' . $port);
    return $cacheKey;
}
