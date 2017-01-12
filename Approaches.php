<?php

class Approaches
{
    public function lock()
    {
        /*
         * 1 retrieve resource identifier and timestamp.
         * 2 check timestamp.
         *    2.1 if timestamp is older than current timestamp, reject.
         *    2.2 otherwise, acquire lock.
         *         2.2.1 if identifier failed to acquire lock, reject.
         *         2.2.2 otherwise, begin transaction.
         *                2.2.2.1 proceed the normal web api operation.
         *                2.2.2.2 save new timestamp.
         *                2.2.2.3 commit.
         *         2.2.3 release lock.
         *
         * NOTE: regatta product and category saving use lock already. this approach will lock two times for those resources.
         */
    }

    // occ stands for optimistic concurrency control
    public function occ()
    {
        /*
         * 1 retrieve resource identifier and timestamp.
         * 2 check timestamp (read current timestamp from database as value 'oldTimestamp').
         *    2.1 if timestamp is older than current timestamp, reject.
         *    2.2 otherwise, begin transaction.
         *         2.2.1 proceed the normal web api operation.
         *         2.2.2 save new timestamp only if the timestamp is not changed since our read. Otherwise, rollback.
         *               SQL: INSERT INTO {Table} VALUES ({myIdentifier}, {newTimestamp})
         *               if insert succeed, commit.
         *               if insert failed primary key already exists, rollback.
         *
         *               SQL: UPDATE {Table} SET timestamp = {newTimestamp} WHERE identifier = {myIdentifier} AND timestamp = {oldTimestamp}
         *               if updated 1 row, commit.
         *               if updated 0 row, rollback as other requests have changed this resource.
         *         2.2.3 commit.
         */
    }
}
