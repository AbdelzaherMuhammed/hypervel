#!/bin/bash

# System-level fixes for "Too many open files" issue
# Run this script with sudo privileges

echo "=== Fixing 'Too many open files' issue ==="

# 1. Set system-wide file descriptor limits
echo "Setting system-wide limits..."

# Add to /etc/security/limits.conf if not already present
if ! grep -q "* soft nofile 65536" /etc/security/limits.conf; then
    echo "* soft nofile 65536" >> /etc/security/limits.conf
fi

if ! grep -q "* hard nofile 65536" /etc/security/limits.conf; then
    echo "* hard nofile 65536" >> /etc/security/limits.conf
fi

if ! grep -q "root soft nofile 65536" /etc/security/limits.conf; then
    echo "root soft nofile 65536" >> /etc/security/limits.conf
fi

if ! grep -q "root hard nofile 65536" /etc/security/limits.conf; then
    echo "root hard nofile 65536" >> /etc/security/limits.conf
fi

# 2. Set systemd service limits (if using systemd)
if command -v systemctl &> /dev/null; then
    echo "Configuring systemd limits..."

    # Create systemd override directory
    mkdir -p /etc/systemd/system/user@.service.d/

    # Create limits override file
    cat > /etc/systemd/system/user@.service.d/limits.conf << EOF
[Service]
LimitNOFILE=65536
EOF

    # Reload systemd
    systemctl daemon-reload
fi

# 3. Set kernel parameters
echo "Setting kernel parameters..."

# Increase system-wide file descriptor limit
echo "fs.file-max = 2097152" >> /etc/sysctl.conf

# Increase network connection limits
echo "net.core.somaxconn = 65536" >> /etc/sysctl.conf
echo "net.ipv4.tcp_max_syn_backlog = 65536" >> /etc/sysctl.conf
echo "net.core.netdev_max_backlog = 65536" >> /etc/sysctl.conf

# Apply kernel parameters
sysctl -p

# 4. Set session limits
echo "session required pam_limits.so" >> /etc/pam.d/common-session

# 5. Create a systemd service file for your application
cat > /etc/systemd/system/hypervel.service << 'EOF'
[Unit]
Description=Hypervel HTTP Server
After=network.target mysql.service redis.service

[Service]
Type=forking
User=www-data
Group=www-data
WorkingDirectory=/path/to/your/hypervel/project
ExecStart=/usr/bin/php /path/to/your/hypervel/project/bin/hypervel.php start
ExecReload=/bin/kill -USR1 $MAINPID
ExecStop=/bin/kill -QUIT $MAINPID
Restart=always
RestartSec=5

# CRITICAL: Set file descriptor limits for the service
LimitNOFILE=65536
LimitNPROC=65536

# Additional limits
LimitCORE=infinity
LimitMEMLOCK=infinity

[Install]
WantedBy=multi-user.target
EOF

echo "=== System fixes applied ==="
echo "Please reboot your system or logout/login for limits to take effect"
echo "Then enable and start the service with:"
echo "  sudo systemctl enable hypervel"
echo "  sudo systemctl start hypervel"

# 6. Create monitoring script
cat > /usr/local/bin/monitor-hypervel.sh << 'EOF'
#!/bin/bash

# Monitor script for Hypervel server
PID_FILE="/path/to/your/project/runtime/hypervel.pid"

if [ -f "$PID_FILE" ]; then
    MAIN_PID=$(cat $PID_FILE)
    echo "=== Hypervel Process Info ==="
    echo "Main PID: $MAIN_PID"

    # Count open file descriptors
    FD_COUNT=$(lsof -p $MAIN_PID 2>/dev/null | wc -l)
    echo "Open file descriptors: $FD_COUNT"

    # Show current limits
    echo "Current limits for PID $MAIN_PID:"
    cat /proc/$MAIN_PID/limits | grep "Max open files"

    # Show all Hypervel processes
    echo "=== All Hypervel Processes ==="
    pgrep -f hypervel | while read pid; do
        fd_count=$(lsof -p $pid 2>/dev/null | wc -l)
        echo "PID: $pid, FDs: $fd_count"
    done

    # Check for zombie connections
    echo "=== Network Connections ==="
    ss -tuln | grep :9501

else
    echo "Hypervel is not running (no PID file found)"
fi
EOF

chmod +x /usr/local/bin/monitor-hypervel.sh

echo "=== Monitoring script created at /usr/local/bin/monitor-hypervel.sh ==="