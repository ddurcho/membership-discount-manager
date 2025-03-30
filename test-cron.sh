#!/bin/bash

# Test script for Membership Discount Manager cron
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="logs/cron-test-${TIMESTAMP}.log"
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CRON_URL="https://nestwork.test/wp-cron.php?doing_wp_cron"

cd "$PLUGIN_DIR"

# Create logs directory if it doesn't exist
mkdir -p logs

echo "Starting Membership Discount Manager cron test..." | tee -a "$LOG_FILE"
echo "Log file: $LOG_FILE" | tee -a "$LOG_FILE"
echo "Start time: $(date)" | tee -a "$LOG_FILE"
echo "Plugin directory: $PLUGIN_DIR" | tee -a "$LOG_FILE"
echo "Cron URL: $CRON_URL" | tee -a "$LOG_FILE"
echo "----------------------------------------" | tee -a "$LOG_FILE"

# Function to run cron and check response
run_cron() {
    echo -e "\nAttempting cron run at $(date)" | tee -a "$LOG_FILE"
    
    # Try to get response headers first
    echo "Checking cron endpoint..." | tee -a "$LOG_FILE"
    HEADERS=$(curl -k -I "$CRON_URL" 2>&1)
    echo "Response headers: $HEADERS" | tee -a "$LOG_FILE"

    # Now run the actual cron request
    echo "Running cron..." | tee -a "$LOG_FILE"
    RESPONSE=$(curl -k "$CRON_URL" 2>&1)
    CURL_EXIT=$?

    if [ $CURL_EXIT -eq 0 ]; then
        echo "Cron request successful" | tee -a "$LOG_FILE"
    else
        echo "Error running cron (exit code: $CURL_EXIT)" | tee -a "$LOG_FILE"
    fi

    # Check WordPress debug log for any errors
    if [ -f "../debug.log" ]; then
        echo "Recent WordPress debug log entries:" | tee -a "$LOG_FILE"
        tail -n 10 "../debug.log" | tee -a "$LOG_FILE"
    fi

    # Check our plugin's log file for updates
    echo "Recent plugin log entries:" | tee -a "$LOG_FILE"
    for f in logs/mdm-*.log; do
        if [ -f "$f" ]; then
            echo "From $f:" | tee -a "$LOG_FILE"
            tail -n 10 "$f" | tee -a "$LOG_FILE"
        fi
    done
}

# Run 12 times (simulating 1 hour of 5-minute intervals)
for i in {1..12}
do
    echo -e "\nRun #$i - $(date)" | tee -a "$LOG_FILE"
    run_cron
    echo -e "\nWaiting 5 minutes before next run..." | tee -a "$LOG_FILE"
    sleep 300
done

echo -e "\n----------------------------------------" | tee -a "$LOG_FILE"
echo "Test completed at: $(date)" | tee -a "$LOG_FILE" 