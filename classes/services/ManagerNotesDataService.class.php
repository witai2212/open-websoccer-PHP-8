<?php

class ManagerNotesDataService {

    public static function getNote(WebSoccer $websoccer, DbConnection $db, $userId) {
        $result = $db->querySelect(
            'note_text, updated_date',
            $websoccer->getConfig('db_prefix') . '_manager_note',
            'user_id = %d',
            (int) $userId,
            1
        );
        $row = $result->fetch_assoc();
        $result->free();
        return $row ? $row : array('note_text' => '', 'updated_date' => 0);
    }

    public static function saveNote(WebSoccer $websoccer, DbConnection $db, $userId, $text) {
        $userId = (int) $userId;
        $text = (string) $text;
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 10000, 'UTF-8');
        } else {
            $text = substr($text, 0, 10000);
        }
        $table = $websoccer->getConfig('db_prefix') . '_manager_note';
        $result = $db->querySelect('user_id', $table, 'user_id = %d', $userId, 1);
        $existing = $result->fetch_assoc();
        $result->free();
        if ($existing) {
            $db->queryUpdate(array('note_text' => $text, 'updated_date' => $websoccer->getNowAsTimestamp()), $table, 'user_id = %d', $userId);
        } else {
            $db->queryInsert(array('user_id' => $userId, 'note_text' => $text, 'updated_date' => $websoccer->getNowAsTimestamp()), $table);
        }
    }
}

?>
