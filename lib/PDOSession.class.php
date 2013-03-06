<?php

class PDOSession
{
    public static $pdo;
    public static $table = 'session_data';

    public function __construct($db)
    {
        // Get a database connection
        self::$pdo = $db;

        // Start session
        session_set_save_handler(array(__CLASS__, '_open'),
                                 array(__CLASS__, '_close'),
                                 array(__CLASS__, '_read'),
                                 array(__CLASS__, '_write'),
                                 array(__CLASS__, '_destroy'),
                                 array(__CLASS__, '_gc'));
        session_start();
    }

#    public function __destruct()
#    {
#        session_write_close();
#    }

    public static function fetchSession($id)
    {
        $stmt = self::$pdo->prepare('SELECT id, data FROM '.self::$table.' WHERE id = :id AND unixtime > :unixtime');
        $stmt->execute(array(':id' => $id, ':unixtime' => (time() - (int)ini_get('session.gc_maxlifetime'))));
        $sessions = $stmt->fetchAll();

        return empty($sessions) ? false : $sessions[0];
    }

    public static function _open($savePath, $sessionName)
    {
        return true;
    }

    public static function _close()
    {
        return true;
    }

    public static function _read($id)
    {
        $session = self::fetchSession($id);
        return ($session === false) ? false : $session['data'];
    }

    public static function _write($id, $sessionData)
    {
        $session = self::fetchSession($id);
        if($session === false) {
            $stmt = self::$pdo->prepare('INSERT INTO '.self::$table.' (id, data, unixtime) VALUES (:id, :data, :time)');
        } else {
            $stmt = self::$pdo->prepare('UPDATE '.self::$table.' SET data = :data, unixtime = :time WHERE id = :id');
        }
        $stmt->execute(array(
                        ':id' => $id,
                        ':data' => $sessionData,
                        ':time' => time()
                        ));
    }

    public static function _destroy($id)
    {
        $stmt = self::$pdo->prepare('DELETE FROM '.self::$table.' WHERE id = :id');
        $stmt->execute(array(':id' => $id));
    }

    public static function _gc($maxlifetime)
    {
        $stmt = self::$pdo->prepare('DELETE FROM '.self::$table.' WHERE unixtime < :time');
        $stmt->execute(array(':time' => (time() - (int) $maxlifetime)));
    }
}
