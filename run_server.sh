#!/bin/bash

if [ $# -lt 1 ]; then
  echo "No port specified, using default 8080"
  port=8080
else
  port=$1
fi

php -S localhost:$port