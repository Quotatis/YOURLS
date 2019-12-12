<?php

/*
Plugin Name:    Popular Clicks Extended
Plugin URI:     http://github.com/vaughany/yourls-popular-clicks-extended
Description:    A YOURLS plugin showing the most popular clicks for given time periods.
Version:        0.4
Release date:   2019-11-11
Author:         Paul Vaughan
Author URI:     http://github.com/vaughany/
*/

/**
 * TODO:
 *      Use global $now instead of time() so that the whole report is consistent.
 *      Use different pages for different types of reports.
 *      Report is in English: use the language functions to provide for potential translations.
 *      Config options to toggle the options: do you really need recent 5 mins or 2 years ago?
 *      Issue/idea on YOURLS repo: https://github.com/yourls/yourls/issues/1732
 */

/**
 * https://github.com/YOURLS/YOURLS/wiki/Coding-Standards
 * https://github.com/YOURLS/YOURLS/wiki#for-developpers
 * https://github.com/YOURLS/YOURLS/wiki/Plugin-List#get-your-plugin-listed-here
*/

// No direct call.
if ( !defined ('YOURLS_ABSPATH') ) { die(); }

// Change to true to get extra debugging info on-screen. Must be true or false, cannot be undefined.
define ( "PCE_DEBUG", false );

// Define the separator between bits of information.
define ( "PCE_SEP", ' | ' );

// Some version details, same as at the top of this file, for use in the page footer.
define ( "PCE_REL_VER",  '0.4' );
define ( "PCE_REL_DATE", '2019-11-11' );

// Repository URL.
define ( "PCE_REPO", 'https://github.com/vaughany/yourls-popular-clicks-extended' );

// Get the GMT offset if it is set
define( "PCE_OFFSET", defined( 'YOURLS_HOURS_OFFSET' ) ? YOURLS_HOURS_OFFSET * 60 * 60 : 0 );

// Blacklist regex. Links matchiing this regex will NOT be included in any reports.
define( "PCE_BLACKLIST", "" );

// Adding actions.
yourls_add_action( 'plugins_loaded', 'vaughany_pce_init' );

/**
 * Standard init function.
 */
function vaughany_pce_init() {
    yourls_register_plugin_page( 'vaughany_pce', 'Popular Clicks Extended', 'vaughany_pce_display_page' );
}

/**
 * vaughany_pce_show_last_period(): queries the database for the number of clicks per link since n seconds ago,
 *     e.g. 'time() - 300' to 'time()'
 *     e.g. '2017-07-15 14:52:27' to '2017-07-15 14:57:27'
 *
 * $period:     integer:    The number of seconds to look back.
 * $rows:       integer:    The number of rows to pull from the database (maximum), defaults to 10.
 * $desc:       string:     Describes the time period for the report.
 */
function vaughany_pce_show_last_period( $period, $rows, $desc ) {
    global $ydb;

    // Check for an appropriate integer, set a default if not appropriate.
    if ( !is_int( $rows ) || $rows == 0 || $rows == null ) {
        $rows = 10;
    }

    // Take the seconds off the current time, then change the timestamp into a date.
    $since = date( 'Y-m-d H:i:s', ( time() - $period + PCE_OFFSET ) );

    $sql = "SELECT a.shorturl AS shorturl, COUNT(*) AS clicks, b.url AS longurl, b.title as title
        FROM " . YOURLS_DB_TABLE_LOG . " a, " . YOURLS_DB_TABLE_URL . " b
        WHERE a.shorturl = b.keyword
            AND click_time >= :since
        GROUP BY a.shorturl
        ORDER BY COUNT(*) DESC, shorturl ASC
        LIMIT :rows;";

    $binds = ['since' => $since, 'rows' => $rows];

    if ( PCE_DEBUG ) {
        echo '<p style="color: #f00;">(' . $sql . ')</p>';
    }

    if ( $results = $ydb->fetchObjects( $sql, $binds ) ) {
        $out = vaughany_pce_render_results( $results );
    } else {
        $out = '<p>No results for the chosen time period.</p>';
    }

    echo '<h3>Popular clicks for the last ' . $desc . ":</h3>";

    if (PCE_DEBUG) {
        echo '<p style="color: #f00;">(Period from ' . $since . ' to now.)</p>';
    }

    echo $out;
}

/**
 * vaughany_pce_show_specific_period(): queries the database for the number of clicks per link per whole period,
 *     e.g. 'today'
 *     e.g. '2017-07-15 00:00:00' to '2017-07-15 23:59:59'
 *
 * $period: string:     Date partial for a single day, format depends on $type.
 * $type:   string:     One of: hour, day, week, month, year; so $period can be processed correctly.
 * $rows:   integer:    The number of rows to pull from the database (maximum), defaults to 10.
 * $desc:   string:     Describes the time period for the report.
 */
function vaughany_pce_show_specific_period( $period, $type, $rows, $desc ) {
    global $ydb;

    // Check for an appropriate integer, set a default if not appropriate.
    if ( !is_int($rows) || $rows == 0 || $rows == null ) {
        $rows = 10;
    }

    // Test for each $type, create $from and $to date bounds accordingly.
    if ( $type == 'hour' ) {
        // Create the bounds for a single hour.
        $from   = $period . ':00:00';
        $to     = $period . ':59:59';
    } else if ( $type == 'day' ) {
        // Create the bounds for a single day.
        $from   = $period . ' 00:00:00';
        $to     = $period . ' 23:59:59';
    } else if ( $type == 'week' ) {
        // Create the bounds for a single week.
        $from   = $period . ' 00:00:00';
        $to     = date( 'Y-m-d', strtotime( $period . ' + 6 days', time() + PCE_OFFSET ) ) . ' 23:59:59';
    } else if ( $type == 'month' ) {
        // Create the bounds for a single month.
        $from   = $period . '-01 00:00:00';
        $to     = date( 'Y-m-d', strtotime( $period . '-' . date( 't', strtotime( $from, time() + PCE_OFFSET ) ), time() + PCE_OFFSET ) ) . ' 23:59:59';
    } else if ( $type == 'year' ) {
        // Create the bounds for a single year.
        $from   = $period . '-01-01 00:00:00';
        $to     = $period . '-12-31 23:59:59';
    } else {
        // If no type is specified, defaults to literally everything (up to 32bit Unix signed integer limit).
        $from   = '1970-01-01 00:00:00';
        $to     = date( 'Y-m-d H:i:s', 2147483647 );
    }

    $sql = "SELECT a.shorturl AS shorturl, COUNT(*) AS clicks, b.url AS longurl, b.title as title
        FROM " . YOURLS_DB_TABLE_LOG . " a, " . YOURLS_DB_TABLE_URL . " b
        WHERE a.shorturl = b.keyword
            AND click_time >= :from
            AND click_time <= :to
        GROUP BY a.shorturl
        ORDER BY COUNT(*) DESC, shorturl ASC
        LIMIT :rows;";

    $binds = ['from' => $from, 'to' => $to, 'rows' => $rows];

    if ( PCE_DEBUG ) {
        echo '<p style="color: #f00;">(' . $sql . ")</p>";
    }

    if ( $results = $ydb->fetchObjects( $sql, $binds ) ) {
        $out = vaughany_pce_render_results( $results );
    } else {
        $out = '<p>No results for the chosen time period.</p>';
    }

    echo '<h3>Popular clicks for ' . $desc . ":</h3>";

    if ( PCE_DEBUG ) {
        echo '<p style="color: #f00;">(Period from ' . $from . ' to ' . $to . ".)</p>";
    }

    echo $out;
}

/**
 * Often-used function to parse and format the results.
 */
function vaughany_pce_render_results( $results ) {

    $total = 0;
    $out = '<table>';
    $out .= '<tr><th>Hits</th><th>Short URL</th><th>Website</th></tr>';
    foreach ( $results as $result ) {
        $total += $result->clicks;
        $out .= '<tr>';
        $out .= '<td>' . $result->clicks . '</td>';
        $out .= '<td><a href="' . YOURLS_SITE . '/' . $result->shorturl . '+" target="blank">' . $result->shorturl . '</a></td>';
        $out .= '<td><a href="' . $result->longurl . '" target="blank">' . $result->title . '</a></td>';
    }
    $out .= '</table>';
    return $out;
}

/**
 * vaughany_show_log() shows the n most recent lines from the log table.
 *
 * $rows:   integer:    The number of rows to pull from the database (maximum), defaults to 10.
 */
function vaughany_show_log( $rows = 10) {

    global $ydb;

    // Check for an appropriate integer, set a default if not appropriate.
    if ( !is_int($rows) || $rows == 0 || $rows == null || $rows == '' ) {
        $rows = 10;
    }

    $sql = "SELECT click_time, ip_address, country_code, referrer, a.shorturl AS shorturl, b.url AS longurl, b.title as title
        FROM " . YOURLS_DB_TABLE_LOG . " a, " . YOURLS_DB_TABLE_URL . " b
        WHERE a.shorturl = b.keyword
        ORDER BY click_time DESC
        LIMIT :rows;";

    $binds = ['rows' => $rows];

    if ( PCE_DEBUG ) {
        echo '<p style="color: #f00;">(' . $sql . ")</p>" . PHP_EOL;
    }
 
    if ( $results = $ydb->fetchObjects( $sql, $binds ) ) {

        $out = "<table border=0>";
        $out .= "<tr>";
        $out .= "<th scope='col'>date</th>";
        $out .= "<th scope='col'>keyword</th>";
        $out .= "<th scope='col'>referrer</th>";
        $out .= "<th scope='col'>Long URL</th>";
        $out .= "<th scope='col'>IP origin</th>";

        $out .= "</tr>";

        foreach ( $results as $result ) {
            $out .= "<tr>";
            $out .= "<td>".$result->click_time."</td>";
            $out .= "<td><a href='".YOURLS_SITE."/".$result->shorturl."+' target='blank'>".$result->shorturl."</a>";
            $out .= "<td>".$result->referrer."</td>";
            $out .= "<td><a href='".$result->longurl."' target='blank'>".$result->title."</a>";

            $out .= "<td>".$result->ip_address;
            $out .= (!empty($result->country_code)) ? PCE_SEP." (".$result->country_code.")" : "";
            $out .= "</td>";

            $out .= "</tr>" . PHP_EOL;
        }
        $out .= "</table>";
    } else {
        $out = '<p>No logs to display.</p>' . PHP_EOL;
    }
    echo $out;
}

function vaughany_pce_recenthits() {
    echo vaughany_show_log();
    //echo "<h1>This is a big pile of poop!!</h1>";
}


function vaughany_pce_this_period() {

    $_time = time() + PCE_OFFSET;

    // Specific hours.
    vaughany_pce_show_specific_period( date( 'Y-m-d H', $_time ), 'hour', null, 'this hour (' . date( 'jS F Y, ga', $_time ) . ' to ' . date( 'ga', strtotime( '+ 1 hour', $_time ) ) . ') (so far)' );
    vaughany_pce_show_specific_period( date( 'Y-m-d H', strtotime( '- 1 hour', $_time ) ), 'hour', null, 'the previous hour (' . date( 'jS F Y, ga', strtotime( '- 1 hour', $_time ) ) . ' to ' . date( 'ga', $_time ) . ')' );
    // Specific days.
    vaughany_pce_show_specific_period( date( 'Y-m-d', $_time ), 'day', null, 'today (' . date( 'jS F Y', $_time ) . ') (so far)' );
    vaughany_pce_show_specific_period( date( 'Y-m-d', strtotime( '- 1 day', $_time ) ), 'day', null, 'yesterday (' . date( 'jS F Y', strtotime( '- 1 day', $_time ) ) . ')' );
    // Specific weeks:
    vaughany_pce_show_specific_period( date( 'Y-m-d', strtotime( 'monday this week', $_time ) ), 'week', null, 'this week (beginning ' . date( 'jS F Y', strtotime( 'monday this week', $_time ) ) . ') (so far)' );
    vaughany_pce_show_specific_period( date( 'Y-m-d', strtotime( 'monday this week - 7 days', $_time ) ), 'week', null, 'last week  (beginning ' . date( 'jS F Y', strtotime( 'monday this week - 7 days', $_time ) ) . ')' );
    // Specific months:
    vaughany_pce_show_specific_period( date( 'Y-m', $_time ), 'month', null, 'this month (' . date( 'F Y', $_time ) . ') (so far)' );
    vaughany_pce_show_specific_period( date( 'Y-m', strtotime( '- 1 month', $_time ) ), 'month', null, 'last month (' . date( 'F Y', strtotime( '- 1 month', $_time ) ) . ')' );
    // Specific years:
    vaughany_pce_show_specific_period( date( 'Y', $_time ), 'year', null, 'this year (' . date( 'Y', $_time ) . ') (so far)');
    vaughany_pce_show_specific_period( date( 'Y', strtotime( '- 1 year', $_time ) ), 'year', null, 'last year (' . date('Y', strtotime( '- 1 year', $_time ) ) . ')' );

}

function vaughany_pce_last_period() {

    /**
     * vaughany_pce_show_last_period() shows all clicks from n seconds ago until now. Note that 24 hours here is not the same as 'yesterday', above.
     */
    vaughany_pce_show_last_period( 60 * 5,                  null, '5 minutes');
    vaughany_pce_show_last_period( 60 * 30,                 null, '30 minutes');
    vaughany_pce_show_last_period( 60 * 60,                 null, 'hour');
    //vaughany_pce_show_last_period( 60 * 60 * 6,             null, '6 hours');
    //vaughany_pce_show_last_period( 60 * 60 * 12,            null, '12 hours');
    vaughany_pce_show_last_period( 60 * 60 * 24,            null, '24 hours');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 2,        null, '2 days');
    vaughany_pce_show_last_period( 60 * 60 * 24 * 7,        null, 'week');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 14,       null, '2 weeks');
    vaughany_pce_show_last_period( 60 * 60 * 24 * 30,       null, 'month');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 60,       null, '2 months');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 90,       null, '3 months');
    vaughany_pce_show_last_period( 60 * 60 * 24 * 180,      null, '6 months');
    vaughany_pce_show_last_period( 60 * 60 * 24 * 365,      null, 'year');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 365 * 2,  null, '2 years');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 365 * 3,  null, '3 years');
    //vaughany_pce_show_last_period( 60 * 60 * 24 * 365 * 4,  null, '4 years');
    // ...and the catch-all:
    //vaughany_pce_show_last_period( time(),                  null, 'billion years');
}
    


function vaughany_pce_display_page() {

    yourls_e( '<h2>Popular Clicks Extended</h2>' );
    echo "<hr>" ;
?>
<link rel="stylesheet" href="<?php yourls_site_url(); ?>/css/infos.css?v=<?php echo YOURLS_VERSION; ?>" type="text/css" media="screen" />
<script src="<?php yourls_site_url(); ?>/js/infos.js?v=<?php echo YOURLS_VERSION; ?>" type="text/javascript"></script>

<div id="tabs">

    <div class="wrap_unfloat">
        <ul id="headers" class="toggle_display stat_tab">
            <li class="selected"><a href="#stat_tab_stats"><h2>Period</h2></a></li>
            <li><a href="#stat_tab_sources"><h2>Last entries</h2></a></li>
        </ul>
    </div>

    <div id="stat_tab_stats" class="tab">
        <h3>Popular clicks for <em>period</em></h3>
        <p><?php echo vaughany_pce_this_period(); ?></p>
    </div>

    <div id="stat_tab_sources" class="tab">
        <h3>Last entries</h3>
        <p>This report shows the last 10 shortURLS created.</p>
        <p>Legend: <em>Position. Clicks<?php echo PCE_SEP; ?>Short URL<?php echo PCE_SEP; ?>Page</em></p>
        <p><?php echo vaughany_show_log(); ?></p>
    </div>

</div>

<?php
    if ( PCE_DEBUG ) {
        echo '<p style="color: #f00;">';
        echo 'Last monday: ' . date( 'Y-m-d', strtotime( 'last monday', time() + PCE_OFFSET ) ) . "<br>" . PHP_EOL;
        echo 'Monday before: ' . date( 'Y-m-d', strtotime( 'last monday - 7 days', time() + PCE_OFFSET ) ) . "<br>" . PHP_EOL;
        echo 'Last month: ' . date( 'Y-m', strtotime( '- 1 month', time() + PCE_OFFSET ) ) . "<br>" . PHP_EOL;
        echo '32-bit max Unix int: ' . date( 'Y-m-d H:i:s', 2147483647) . PHP_EOL;
        echo '</p>';
    }
}
?>
