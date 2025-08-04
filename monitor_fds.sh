#!/bin/bash

# monitor_fds.sh - Monitor file descriptors for your application

PID_FILE="runtime/hypervel.pid"

if [ ! -f "$PID_FILE" ]; then
    echo "PID file not found: $PID_FILE"
    exit 1
fi

PID=$(cat "$PID_FILE")

if ! kill -0 "$PID" 2>/dev/null; then
    echo "Process $PID is not running"
    exit 1
fi

echo "Monitoring file descriptors for PID: $PID"
echo "========================================="

while true; do
    # Get current FD count
    FD_COUNT=$(ls /proc/$PID/fd/ 2>/dev/null | wc -l)

    # Get system limits
    SOFT_LIMIT=$(cat /proc/$PID/limits | grep "Max open files" | awk '{print $4}')
    HARD_LIMIT=$(cat /proc/$PID/limits | grep "Max open files" | awk '{print $5}')

    # Calculate percentage
    PERCENTAGE=$(echo "scale=2; $FD_COUNT * 100 / $SOFT_LIMIT" | bc -l)

    # Color coding
    if (( $(echo "$PERCENTAGE > 80" | bc -l) )); then
        COLOR='\033[0;31m'  # Red
    elif (( $(echo "$PERCENTAGE > 60" | bc -l) )); then
        COLOR='\033[0;33m'  # Yellow
    else
        COLOR='\033[0;32m'  # Green
    fi

    NC='\033[0m' # No Color

    echo -e "$(date): ${COLOR}FDs: $FD_COUNT/$SOFT_LIMIT (${PERCENTAGE}%)${NC}"

    # Alert if getting close to limit
    if (( $(echo "$PERCENTAGE > 90" | bc -l) )); then
        echo "WARNING: File descriptor usage is very high!"

        # Show top file descriptor types
        echo "Top FD types:"
        find /proc/$PID/fd/ -type l -exec readlink {} \; 2>/dev/null | sort | uniq -c | sort -nr | head -10
    fi

    sleep 5
done