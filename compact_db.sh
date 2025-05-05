#!/bin/bash

sqlite3 yourdatabase.db "PRAGMA wal_checkpoint(TRUNCATE); VACUUM;"