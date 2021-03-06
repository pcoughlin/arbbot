<?php
require_once __DIR__ . '/../lib/mysql.php';
class Database {

  const STATISTICS_MAX_AGE = 432000;

  private static function connect() {

    $dbHost = Config::get( Config::DB_HOST, null );
    $dbName = Config::get( Config::DB_NAME, null );
    $dbUser = Config::get( Config::DB_USER, null );
    $dbPass = Config::get( Config::DB_PASS, null );

    if ( is_null( $dbHost ) || is_null( $dbName ) || is_null( $dbUser ) || is_null( $dbPass ) ) {
      throw new Exception( 'Database configuration data missing or incomplete' );
    }

    $link = mysql_connect( $dbHost, $dbUser, $dbPass, true );
    if ( !$link ) {
      throw new Exception( 'database error: ' . mysql_error( $link ) );
    }
    mysql_select_db( $dbName, $link );
    return $link;

  }

  /*
   * Helper to clean too frequent track entries in db
    public static function cleanTrack() {

    $link = self::connect();

    $query = "SELECT * FROM track;";

    $result = mysql_query( $query, $link );
    if ( !$result ) {
    throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $opb = [ ];

    $deletions = [ ];

    $tracks = [ ];

    echo "REMOVING USELESS TRACKS...\n";

    while ( $row = mysql_fetch_assoc( $result ) ) {

    $id = $row[ 'ID' ];
    $xid = $row[ 'ID_exchange' ];
    $coin = $row[ 'coin' ];
    $created = $row[ 'created' ];

    if ( !array_key_exists( $coin, $opb ) ) {
    $opb[ $coin ] = self::getOpportunityCount( $coin, 0 );
    }

    if ( !array_key_exists( $xid, $tracks ) ) {
    $tracks[ $xid ] = [ ];
    }
    if ( !array_key_exists( $coin, $tracks[ $xid ] ) ) {
    $tracks[ $xid ][ $coin ] = 0;
    }

    if ( $created < $tracks[ $xid ][ $coin ] + 3600 ) {
    echo "Dropping track $id ($coin @ $xid)\n";

    mysql_query( "DELETE FROM track WHERE ID = $id", $link );

    if ( !array_key_exists( $coin, $deletions ) ) {
    $deletions[ $coin ] = 0;
    }
    $deletions[ $coin ] ++;
    }
    else {
    $tracks[ $xid ][ $coin ] = $created;
    }
    }

    echo "\n\nSUMMARY:\n";
    foreach ( $deletions as $coin => $value ) {
    $opa = self::getOpportunityCount( $coin, 0 );
    echo "$coin has $value deletions | ";
    echo $opb[ $coin ] . " uses before | ";
    echo "$opa uses after!";
    if ( $opb[ $coin ] >= 3 && $opa < 3 ) {
    echo " (KILLED A COIN)";
    }
    echo "\n";
    }

    mysql_close( $link );

    }
   */

  public static function cleanup() {

    $link = self::connect();

    $age = time() - Config::get( Config::MAX_LOG_AGE, Config::DEFAULT_MAX_LOG_AGE ) * 3600;

    if ( !mysql_query( sprintf( "DELETE FROM log WHERE created < %d;", $age ), $link ) ) {
      throw new Exception( "database cleanup error: " . mysql_error( $link ) );
    }

    $rows = mysql_affected_rows( $link );

    mysql_close( $link );

    return $rows;

  }

  public static function insertAlert( $type, $message ) {

    $link = self::connect();

    if ( !mysql_query( sprintf( "INSERT INTO alerts (type, message, created) VALUES ('%s', '%s', %d);",
                                mysql_escape_string( $type ),
                                mysql_escape_string( strip_tags( $message ) ), time() ), $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  public static function log( $message ) {

    $link = self::connect();

    if ( !mysql_query( sprintf( "INSERT INTO log (message, created) VALUES ('%s', %d);", mysql_escape_string( strip_tags( $message ) ), time() ), $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  public static function saveSnapshot( $coin, $balance, $desiredBalance, $rate, $exchangeID, $time ) {

    $link = self::connect();
    $query = sprintf( "INSERT INTO snapshot (coin, balance, desired_balance, uses, trades, rate, ID_exchange, created) VALUES ('%s', '%s', '%s', %d, %d, '%s', %d, %d);", //
            $coin, //
            formatBTC( $balance ), //
            formatBTC( $desiredBalance ), //
            self::getOpportunityCount( $coin, $exchangeID ), //
            self::getTradeCount( $coin, $exchangeID ), //
            formatBTC( $rate ), //
            $exchangeID, //
            $time //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveManagement( $coin, $amount, $rate, $exchange ) {
    $link = self::connect();
    $query = sprintf( "INSERT INTO management (amount, coin, rate, ID_exchange, created) VALUES ('%s', '%s', '%s', %d, %d);", //
            formatBTC( $amount ), //
            $coin, //
            formatBTC( $rate ), //
            $exchange, //
            time() //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveTrack( $coin, $amount, $profit, $exchange ) {

    $link = self::connect();

    $exchangeID = $exchange->getID();
    $exchangeName = $exchange->getName();

    $lastTrackTime = self::getLastTrackTime( $coin, $exchangeID );
    if ( $lastTrackTime > time() - Config::get( Config::OPPORTUNITY_SAVE_INTERVAL, Config::DEFAULT_OPPORTUNITY_SAVE_INTERVAL ) * 60 ) {
      logg( "[DB] Omitting track $amount $coin @ $exchangeName as previous entry is too young" );
      return;
    }

    $query = sprintf( "INSERT INTO track (amount, coin, profit, ID_exchange, created) VALUES ('%s', '%s', '%s', %d, %d);", //
            formatBTC( $amount ), //
            $coin, //
            formatBTC( $profit ), //
            $exchangeID, //
            time() //
    );

    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }

    mysql_close( $link );

  }

  public static function getLastTrackTime( $coin, $exchangeID ) {

    $link = self::connect();

    $query = sprintf( "SELECT MAX(created) AS created FROM track WHERE coin = '%s' AND ID_exchange = %d;", //
            $coin, //
            $exchangeID
    );

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $data = 0;
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $data = $row[ 'created' ];
    }

    mysql_close( $link );

    return $data;

  }

  public static function saveTrade( $coin, $currency, $amount, $exchangeSource, $exchangeTarget ) {
    $link = self::connect();
    $query = sprintf( "INSERT INTO trade (coin, currency, amount, ID_exchange_source, ID_exchange_target, created) VALUES ('%s', '%s', '%s', %d, %d, %d);", //
            $coin, //
            $currency, //
            formatBTC( $amount ), //
            $exchangeSource, //
            $exchangeTarget, //
            time() //
    );
    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveWithdrawal( $coin, $amount, $address, $sourceExchangeID, $targetExchangeID ) {

    $link = self::connect();
    $query = sprintf( "INSERT INTO withdrawal (amount, coin, address, ID_exchange_source, ID_exchange_target, created) VALUES ('%s', '%s', '%s', %d, %d, %d);", //
            formatBTC( $amount ), //
            $coin, //
            $address, //
            $sourceExchangeID, //
            $targetExchangeID, //
            time() //
    );

    if ( !mysql_query( $query, $link ) ) {
      throw new Exception( "database insertion error: " . mysql_error( $link ) );
    }
    mysql_close( $link );

  }

  public static function saveStats( $stats ) {
    $link = self::connect();

    foreach ( $stats as $key => $value ) {

      $query = sprintf( "INSERT INTO stats (keyy, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = '%s';", //
              mysql_escape_string( $key ), //
              mysql_escape_string( $value ), //
              mysql_escape_string( $value )
      );
      if ( !mysql_query( $query, $link ) ) {
        throw new Exception( "database insertion error ($query): " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

  }

  public static function getWalletStats() {

    $link = self::connect();

    $query = 'SELECT * FROM snapshot WHERE created = (SELECT MAX(created) FROM snapshot)';

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = [ ];
    while ( $row = mysql_fetch_assoc( $result ) ) {

      $coin = $row[ 'coin' ];
      $exid = $row[ 'ID_exchange' ];

      $results[ $coin ][ $exid ][ 'balance' ] = $row[ 'balance' ];
      $results[ $coin ][ $exid ][ 'desired_balance' ] = $row[ 'desired_balance' ];
      $results[ $coin ][ $exid ][ 'balance_diff' ] = formatBTC( $row[ 'desired_balance' ] - $row[ 'balance' ] );
      $results[ $coin ][ $exid ][ 'opportunities' ] = $row[ 'uses' ];
    }

    mysql_close( $link );

    ksort( $results );

    return $results;

  }

  public static function getOpportunityCount( $coin, $exchangeID ) {

    $maxAge = time() - Config::get( Config::OPPORTUNITY_COUNT_AGE, Config::DEFAULT_OPPORTUNITY_COUNT_AGE ) * 3600;

    $link = self::connect();

    $query = sprintf( "SELECT COUNT(ID) AS CNT FROM track WHERE coin = '%s' %s AND created >= %d", //
            mysql_escape_string( $coin ), //
            $exchangeID > 0 ? sprintf( "AND ID_exchange = %d", $exchangeID ) : "", //
            $maxAge //
    );

    $data = mysql_query( $query, $link );
    if ( !$data ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $result = null;
    while ( $row = mysql_fetch_assoc( $data ) ) {
      $result = $row[ 'CNT' ];
    }

    mysql_close( $link );

    // do not allow less opportunities than actual trades!
    return max( self::getTradeCount( $coin, $exchangeID ), $result );

  }

  public static function getTradeCount( $coin, $exchangeID ) {

    $maxAge = time() - Config::get( Config::OPPORTUNITY_COUNT_AGE, Config::DEFAULT_OPPORTUNITY_COUNT_AGE ) * 3600;

    $link = self::connect();

    $query = sprintf( "SELECT COUNT(ID) AS CNT FROM trade WHERE coin = '%s' %s AND created >= %d", //
            mysql_escape_string( $coin ), //
            $exchangeID > 0 ? sprintf( "AND ID_exchange_target = %d", $exchangeID ) : "", //
            $maxAge //
    );

    $data = mysql_query( $query, $link );
    if ( !$data ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $result = null;
    while ( $row = mysql_fetch_assoc( $data ) ) {
      $result = $row[ 'CNT' ];
    }

    mysql_close( $link );

    return $result;

  }

  public static function getAverageRate( $coin ) {

    $link = self::connect();

    $query = sprintf( "SELECT AVG(rate) AS rate FROM snapshot WHERE coin = '%s' GROUP BY created", mysql_escape_string( $coin ) );

    $result = mysql_query( $query, $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    if ( mysql_num_rows( $result ) < 5 ) {
      return -1;
    }

    $period = Config::get( Config::RATE_EMA_PERIOD, Config::DEFAULT_RATE_EMA_PERIOD );
    $k = 2 / ($period + 1);

    $ema = -1;
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $rate = $row[ "rate" ];

      $ema = $rate * $k + ($ema < 0 ? $rate : $ema) * (1 - $k);
    }

    mysql_close( $link );

    return formatBTC( $ema );

  }

  public static function handleAddressUpgrade() {

    $link = self::connect();

    $result = mysql_query( "SHOW COLUMNS FROM withdrawal LIKE 'address';", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    $row = mysql_fetch_assoc( $result );
    if ( $row[ 'Type' ] == 'char(35)' ) {
      // Old database format, need to upgrade first.
      $result = mysql_query( "ALTER TABLE withdrawal MODIFY address TEXT NOT NULL;", $link );
      if ( !$result ) {
        throw new Exception( "database selection error: " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

    return $results;

  }

  public static function getStats() {

    $link = self::connect();

    $result = mysql_query( "SELECT * FROM stats", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $results = array();
    while ( $row = mysql_fetch_assoc( $result ) ) {
      $results[ $row[ "keyy" ] ] = $row[ "value" ];
    }

    mysql_close( $link );

    return $results;

  }

  public static function alertsTableExists() {

    $link = self::connect();

    if ( !mysql_query( sprintf( "SELECT * FROM information_schema.tables WHERE table_schema = '%s' " .
                                "AND table_name = 'alerts' LIMIT 1;",
                                mysql_escape_string( Config::get( Config::DB_NAME, null ) ) ), $link ) ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    $rows = mysql_affected_rows( $link );
    $result = $rows > 0;

    mysql_close( $link );

    return $result;

  }

  public static function createAlertsTable() {

    $link = self::connect();

    $query = file_get_contents( __DIR__ . '/../alerts.sql' );

    foreach ( explode( ';', $query ) as $q ) {
      $q = trim( $q );
      if ( !strlen( $q ) ) {
        continue;
      }
      if ( !mysql_query( $q, $link ) ) {
        throw new Exception( "database insertion error: " . mysql_error( $link ) );
      }
    }

    mysql_close( $link );

    return true;

  }

  public static function importAlerts() {

    $link = self::connect();

    $result = mysql_query( "SELECT ID, created FROM log WHERE message = 'stuckDetection()' ORDER BY created ASC", $link );
    if ( !$result ) {
      throw new Exception( "database selection error: " . mysql_error( $link ) );
    }

    while ( $row = mysql_fetch_assoc( $result ) ) {
      $id = $row[ 'ID' ];
      // Poor man's progress bar
      print strftime( "\rLooking at stuck withdrawal check performed on %Y-%m-%d %H:%M:%S", $row[ 'created' ] );

      $result2 = mysql_query( "SELECT created, message FROM log WHERE ID > $id ORDER BY ID ASC", $link );
      if ( !$result2 ) {
	throw new Exception( "database selection error: " . mysql_error( $link ) );
      }

      while ( $row = mysql_fetch_assoc( $result2 ) ) {
	if (!preg_match( '/Please investigate and open support ticket if neccessary/', $row[ 'message' ] )) {
	  break;
	}
	$result3 = mysql_query( sprintf( "INSERT INTO alerts(type, created, message) " .
                                         "VALUES ('stuck-transfer', %d, '%s');",
                                         $row[ 'created' ],
                                         mysql_escape_string( $row[ 'message' ] ) ),
                                $link );
	if ( !$result3 ) {
	  throw new Exception( "database selection error: " . mysql_error( $link ) );
	}
      }
    }

    print "\n";

    mysql_close( $link );

  }

}
