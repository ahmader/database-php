<?php
/*
Copyright (c) 2009 <ahmader@gmail.com>

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

namespace Ahmader;

class Database {
	var $charset='utf8';

	function __construct($dbtype, $dbhost,  $dbuser, $dbpassword, $dbname ) {
		if (empty($dbtype) || !in_array($dbtype, array("mysql", "mssql"))) die("UNKNOWN database type!");
			
		$this->$dbtype = true; /* put type as an object for easy isset() */
		$this->dbhost = $dbhost;
		$this->dbuser = $dbuser;
		$this->dbpassword = $dbpassword;
		$this->dbname = $dbname;
		

		$this->last_query='';
		$this->last_error='';
		$this->last_errno=0;
		$this->ready = false;


		$this->encoding = '';
		if ( defined( 'DB_CHARSET' ) ) {
			$this->charset = DB_CHARSET;
			$this->encoding = "DEFAULT CHARACTER SET ".DB_CHARSET." ";
		}
		if ( defined('DB_COLLATE') ) {
			$this->collate = DB_COLLATE;
			$this->encoding .= "COLLATE ".DB_COLLATE;
		}

		return $this->connect();
	}

	/**
	 * PHP5 style destructor and will run when database object is destroyed.
	 *
	 * @see mhdb::__construct()
	 * @since 2.0.8
	 * @return bool true
	 */
	function __destruct() {
		return true;
	}

	public function IsConnected($force=false) { 
		// if no connection return new one
		if ($this->dbh===false) return $this->connect();

		// returns true if the connection to the DB server still works. 
		if (isset($this->mysql) && mysql_ping ($this->dbh)) return true;
		// If it has gone down
		// here is the major trick, you have to close the connection (even though its not currently working) for it to recreate properly.
		$this->close();
		return $this->connect();
	} 
	function close() {
		if ($this->dbh===false) return;
		if (isset($this->mssql)) {
			@mssql_close ($this->dbh);
		} else {
			@mysql_close ($this->dbh);
		}
	}
	function connect() {
		
		$this->last_errno=0;
		$this->last_error='';
		
		if (isset($this->mssql)) {
			$this->dbh = @mssql_connect( $this->dbhost, $this->dbuser, $this->dbpassword, true );
		} else {
			$this->dbh = @mysql_connect( $this->dbhost, $this->dbuser, $this->dbpassword, true );
		}
		if ( !$this->dbh) {
			$this->ready = false;
			if (isset($this->mssql)) {
				$this->last_error = mssql_get_last_message();
				if (empty($this->last_error)) $this->last_error='Unable to connect to mssql: '.$this->dbhost;
				$this->last_errno = 1;
			} else {
				$this->last_error = mysql_error( );
				$this->last_errno = mysql_errno( );			
			}
			
			return false;
		}

		$this->ready = true;
		$this->real_escape = true;
		
		
		$this->select( $this->dbname, $this->dbh );
	
	}

	function prepare( $query = null ) { // ( $query, *$args )
		if ( is_null( $query ) )
			return;

		$args = func_get_args();
		array_shift( $args );
		// If args were passed as an array (as in vsprintf), move them up
		if ( isset( $args[0] ) && is_array($args[0]) )
			$args = $args[0];
		$query = str_replace( "'%s'", '%s', $query ); // in case someone mistakenly already singlequoted it
		$query = str_replace( '"%s"', '%s', $query ); // doublequote unquoting
		$query = preg_replace( '|(?<!%)%s|', "'%s'", $query ); // quote the strings, avoiding escaped strings like %%s
		array_walk( $args, array( &$this, 'escape_by_ref' ) );
		return @vsprintf( $query, $args );
	}

	function select( $db, $dbh = null) {
		if ( is_null($dbh) ) 
			$dbh = &$this->dbh;

		if (isset($this->mssql)) {
			if ( !@mssql_select_db( $db, $this->dbh ) ) {
				$this->last_error =mssql_get_last_message();
				$this->last_errno =1;
				return false;
			}
		} else {
			mysql_set_charset( $this->charset, $this->dbh );
			
			if ( !@mysql_select_db( $db, $this->dbh ) ) {
				$this->last_error = mysql_error();
				$this->last_errno = mysql_errno();
				return false;
			}
		}

		
		return true;
	}
	
	function clear_errors() {
		$this->last_error='';
		$this->last_errno =0;
	}
	
	function last_error() {
		if (!empty($this->last_error)) return $this->last_error;
		$this->clear_errors();
		if (isset($this->mssql)) {
			$this->last_error =mssql_get_last_message();
			$this->last_errno =1;
		} else {
			$this->last_error = mysql_error();
			$this->last_errno = mysql_errno();
		}
		return $this->last_error;
	}

	function query( $query , $bypass_errors = false) {
		$dbh =& $this->dbh;

		if ( ! $this->ready )
			return false;
			
		if (isset($this->mssql)) 
			$query=str_replace('`', '', $query); /* replace ` with " for mssql */

		$this->clear_errors();
		$this->last_query = $query;
		
		if (isset($this->mssql)) {
			//$query = 'select * from mh_messages WHERE "APPROVED"=\'0\' and "POSTED_STATUS" is NULL and "POSTED_RESPONSE" is NULL and "POSTED_STAMP" is NULL;';
//			$query = 'select * from mh_messages WHERE APPROVED=0 and POSTED_STATUS IS NULL and POSTED_STAMP is NULL;';
			$this->result = @mssql_query( $query, $dbh );
		}else{
			$this->result = @mysql_query( $query, $dbh );
		}
		
		
		if ($this->result ===false && $this->last_error()) {
			return false;
		}
		

		if ( preg_match( "/^\\s*(insert|delete|update|replace|alter) /i", $query ) ) {
			if (isset($this->mssql)) {
				$this->rows_affected = mssql_rows_affected( $dbh );
					
				// Take note of the insert_id
				if ( preg_match( "/^\\s*(insert|replace) /i", $query ) ) {
					$this->insert_id = mssql_get_last_message(); //FIXME check if this works ??
				}
			}else{
				$this->rows_affected = mysql_affected_rows( $dbh );
				
				// Take note of the insert_id
				if ( preg_match( "/^\\s*(insert|replace) /i", $query ) ) {
					$this->insert_id = mysql_insert_id($dbh);
				}
			}
		
			
			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$return_val=$this->result;
		}

		return $return_val;
	}

	function insert( $table, $data, $format = null ) {
		return $this->_insert_replace_helper( $table, $data, $format, 'INSERT' );
	}

	
	function replace( $table, $data, $format = null ) {
		return $this->_insert_replace_helper( $table, $data, $format, 'REPLACE' );
	}

	
	function _insert_replace_helper( $table, $data, $format = null, $type = 'INSERT' ) {
		if ( ! in_array( strtoupper( $type ), array( 'REPLACE', 'INSERT' ) ) )
			return false;
		$formats = $format = (array) $format;
		$fields = array_keys( $data );
		$formatted_fields = array();
		foreach ( $fields as $field ) {
			if ( !empty( $format ) )
				$form = ( $form = array_shift( $formats ) ) ? $form : $format[0];
			elseif ( isset( $this->field_types[$field] ) )
				$form = $this->field_types[$field];
			else
				$form = '%s';
			$formatted_fields[] = $form;
		}
		if (substr($table, 0, 1)!=='`') $table="`$table`";
		$sql = "{$type} INTO $table (`" . implode( '`,`', $fields ) . "`) VALUES ('" . implode( "','", $formatted_fields ) . "')";
		
		
		if (isset($this->mssql)) 
			$sql=str_replace('`', '', $sql); /* replace ` with " for mssql */

		return $this->query( $this->prepare( $sql, $data ) );
	}

	function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( ! is_array( $data ) || ! is_array( $where ) )
			return false;

		$formats = $format = (array) $format;
		$bits = $wheres = array();
		foreach ( (array) array_keys( $data ) as $field ) {
			if ( !empty( $format ) )
				$form = ( $form = array_shift( $formats ) ) ? $form : $format[0];
			elseif ( isset($this->field_types[$field]) )
				$form = $this->field_types[$field];
			else
				$form = '%s';
			$bits[] = "`$field` = {$form}";
		}

		$where_formats = $where_format = (array) $where_format;
		foreach ( (array) array_keys( $where ) as $field ) {
			if ( !empty( $where_format ) )
				$form = ( $form = array_shift( $where_formats ) ) ? $form : $where_format[0];
			elseif ( isset($this->field_types[$field]) )
				$form = $this->field_types[$field];
			else
				$form = '%s';
			
			$wheres[] = "`$field` = {$form}";
		}

		if (substr($table, 0, 1)!=='`') $table="`$table`";

		
		$sql = "UPDATE $table SET " . implode( ', ', $bits ) . ' WHERE ' . implode( ' AND ', $wheres );
		
		if (isset($this->mssql)) 
			$sql=str_replace('`', '', $sql); /* replace ` with " for mssql */

		return $this->query( $this->prepare( $sql, array_merge( array_values( $data ), array_values( $where ) ) ) );
	}

	function mssql_escape($data) {
		return str_replace("'", "''", $data);
		/*
		if(is_numeric($data) || is_date($data) )
			return $data;
		$unpacked = unpack('H*hex', $data);
		return '0x' . $unpacked['hex'];
		*/
	}

	function escape_by_ref( &$string ) {
		$string = $this->_real_escape( $string );
	}

	function _real_escape( $string ) {
		if ( $this->dbh && $this->real_escape )
			return isset($this->mssql) ? $this->mssql_escape($string)  : mysql_real_escape_string( $string, $this->dbh );
		else
			return addslashes( $string );
	}

	function get_results($query , $bypass_errors = false) {
		$result = $this->query( $query);
		if ($result===false) return false;

		$rows = array();
		if (isset($this->mssql)) {
			while ($row = mssql_fetch_object( $this->result ) ) {
				$rows[]=$row;
			}
			@mssql_free_result($result);
		} else {
			while ($row = @mysql_fetch_object( $result ) ) {
				$rows[]=$row;
			}
			@mysql_free_result($result);
		}
		return ($rows);
	}

	
	function count($query) {
		$result = $this->query( $query );
		if ($result===false) return false;
		$rows = array();
		$count=@mysql_num_rows($result);
		@mysql_free_result($result);
		return (int)$count;
	}

	
	function create_database($name) {
		$ms_queries = "CREATE DATABASE IF NOT EXISTS `{$name}` {$this->encoding};";
		return $this->query( $ms_queries );
	}

	function make_query_string($mysql) {
		$query='';

		if (isset($mysql->where)) {
			if (is_string($mysql->where)) {
				if (!empty($mysql->where)) $query.=' where '.$mysql->where.' ';
			} elseif (is_object($mysql->where)) {
				foreach($mysql->where as $field=>$value) {

					$fieldname=((isset($mysql->map->$field) && !empty($mysql->map->$field)) ? $mysql->map->$field : $field);
					if (is_null($value))
					$query.="`$fieldname` is NULL and ";
					elseif (is_array($value))
					$query.="`$fieldname` in ('".implode("','", $value)."') and ";
					else
					$query.="`$fieldname`='$value' and ";
				}
				if (strlen($query)>0) $query=' where '.substr($query, 0, -1*strlen(' and '));
			}
		}
		if (isset($mysql->order) && !empty($mysql->order)) {
			$query.="order by {$mysql->order} ";
		}

		$top='';
		if (isset($mysql->limit)) {
			if (isset($this->mssql)) {
				$top="top {$mysql->limit} ";
			} else {
				$top="limit {$mysql->limit} ";
			}
		}
		
		if (isset($this->mssql)) {
			$query=str_replace('`', '', $query);
			$sql="select {$top}* from {$mysql->table}{$query};";
		}else{
			$sql="select * from `{$mysql->database}`.`{$mysql->table}`{$query}{$top};";
		}
		return $sql;
	}
}

if (!function_exists('stripslashes_deep')) {
	function stripslashes_deep($value) {
		if ( is_array($value) ) {
			$value = array_map('stripslashes_deep', $value);
		} elseif ( is_object($value) ) {
			$vars = get_object_vars( $value );
			foreach ($vars as $key=>$data) {
				$value->{$key} = stripslashes_deep( $data );
			}
		} else {
			$value = stripslashes($value);
		}

		return $value;
	}
}
?>
