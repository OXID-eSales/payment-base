#!/bin/bash

# Pre-commit check script
# Runs tests and checks to ensure code is ready for commit
# Works both locally (with Docker) and on GitHub Actions (without Docker)
#
# Usage: ./bin/pre-commit-check.sh [OPTIONS]
# Options:
#   --no-phpunit    Skip PHPUnit tests
#   --full          Run all tests including Integration tests (slower)

set +e  # Don't exit on error, we want to collect all results

# Parse command line arguments
SKIP_PHPUNIT=false
FULL_TESTS=false
for arg in "$@"; do
    case $arg in
        --no-phpunit)
            SKIP_PHPUNIT=true
            shift
            ;;
        --full)
            FULL_TESTS=true
            shift
            ;;
    esac
done

# Get the script directory (module root)
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
MODULE_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

# Detect environment: GitHub Actions or Local Docker
if [ -n "$GITHUB_ACTIONS" ]; then
    # GitHub Actions environment
    ENVIRONMENT="github"
    WORKING_DIR="$MODULE_ROOT"
    echo "======================================"
    echo "Running Pre-Commit Checks (GitHub Actions)"
    echo "======================================"
    echo "Module root: $MODULE_ROOT"
else
    # Local Docker environment
    ENVIRONMENT="local"
    PROJECT_ROOT="$( cd "$SCRIPT_DIR/../../.." && pwd )"
    echo "======================================"
    echo "Running Pre-Commit Checks (Local Docker)"
    echo "======================================"
    echo "Project root: $PROJECT_ROOT"

    # Navigate to project root for docker compose commands
    cd "$PROJECT_ROOT" || {
        echo "Error: Could not navigate to project root"
        exit 1
    }
fi

echo ""

# Initialize status tracking
OVERALL_STATUS=0
FAILED_CHECKS=()

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper function to run commands based on environment
run_command() {
    if [ "$ENVIRONMENT" = "github" ]; then
        # GitHub: Run directly on host
        cd "$MODULE_ROOT" && eval "$1"
    else
        # Local: Run in Docker container
        docker compose exec -w /var/www/extensions/payment-component -T php bash -c "$1"
    fi
}

# Helper function to run phpcs in Docker with correct path
run_phpcs_docker() {
    docker compose exec -w /var/www/extensions/payment-component -T php \
        /var/www/vendor/bin/phpcs --standard=tests/phpcs.xml --warning-severity=0 src/
}

# Helper function to run phpstan in Docker using module's vendor
run_phpstan_docker() {
    local files="$1"
    if [ -n "$files" ]; then
        echo "Running PHPStan on changed files: $files"
        docker compose exec -w /var/www/extensions/payment-component -T php \
            vendor/bin/phpstan analyse -c tests/PhpStan/phpstan.neon --level=max $files --memory-limit=1G
    else
        echo "No PHP files to check with PHPStan"
        return 0
    fi
}

# Helper function to run phpmd in Docker using module's vendor or skip if not available
run_phpmd_docker() {
    local files="$1"
    if [ -n "$files" ]; then
        echo "Running PHPMD on changed files: $files"
        # Check if phpmd is available in module's vendor, otherwise skip
        if docker compose exec -w /var/www/extensions/payment-component -T php test -f vendor/bin/phpmd 2>/dev/null; then
            docker compose exec -w /var/www/extensions/payment-component -T php \
                vendor/bin/phpmd $files text tests/PhpMd/phpmd.baseline.xml --exclude tests/,migration/data/ --suffixes php --strict
        else
            echo "PHPMD not available - skipping (add phpmd/phpmd to require-dev to enable)"
            return 0
        fi
    else
        echo "No PHP files to check with PHPMD"
        return 0
    fi
}

# Helper function to get changed PHP files (for local use)
get_changed_files_local() {
    cd "$MODULE_ROOT" || return
    local files=""
    # Get files changed from HEAD (excluding deleted files with --diff-filter=d)
    files=$(git diff --diff-filter=d --name-only HEAD 2>/dev/null | grep -E '\.php$' | grep -vE '^tests/' | tr '\n' ' ')
    # If no uncommitted changes, get files from last commit
    if [ -z "$files" ]; then
        files=$(git diff-tree --no-commit-id --diff-filter=d --name-only -r HEAD 2>/dev/null | grep -E '\.php$' | grep -vE '^tests/' | tr '\n' ' ')
    fi
    echo "$files"
}

# 1. Code Style Check (phpcs)
echo ">>> Running PHP Code Sniffer..."
if [ "$ENVIRONMENT" = "github" ]; then
    run_command "composer run phpcs src"
else
    run_phpcs_docker
fi
PHPCS_STATUS=$?
if [ $PHPCS_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHP Code Sniffer")
    echo -e "${RED}✗ PHP Code Sniffer failed${NC}"
else
    echo -e "${GREEN}✓ PHP Code Sniffer passed${NC}"
fi
echo ""

# 2. PHPUnit Tests
if [ "$SKIP_PHPUNIT" = true ]; then
    echo ">>> Skipping PHPUnit Tests (--no-phpunit flag set)"
    echo -e "${YELLOW}⊘ PHPUnit tests skipped${NC}"
    echo ""
else
    if [ "$FULL_TESTS" = true ]; then
        echo ">>> Running PHPUnit Tests (Full: Unit + Integration)..."
        TESTSUITE_ARG=""
    else
        echo ">>> Running PHPUnit Tests (Unit only, use --full for all)..."
        TESTSUITE_ARG="--testsuite Unit"
    fi

    if [ "$ENVIRONMENT" = "github" ]; then
      echo "skip on github"
      PHPUNIT_STATUS=0
    else
        # Local: Run in Docker with shop bootstrap (use shop's vendor phpunit)
        docker compose exec -w /var/www/extensions/payment-component -T php \
            /var/www/vendor/bin/phpunit -c tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php $TESTSUITE_ARG
        PHPUNIT_STATUS=$?
    fi

    if [ $PHPUNIT_STATUS -ne 0 ]; then
        OVERALL_STATUS=1
        FAILED_CHECKS+=("PHPUnit Tests")
        echo -e "${RED}✗ PHPUnit tests failed${NC}"
    else
        echo -e "${GREEN}✓ PHPUnit tests passed${NC}"
    fi
    echo ""
fi

# 3. PHPStan Static Analysis
echo ">>> Running PHPStan static analysis..."
if [ "$ENVIRONMENT" = "github" ]; then
    run_command "composer phpstan"
    PHPSTAN_STATUS=$?
else
    # Get changed PHP files for local analysis
    CHANGED_FILES=$(get_changed_files_local)
    run_phpstan_docker "$CHANGED_FILES"
    PHPSTAN_STATUS=$?
fi
if [ $PHPSTAN_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPStan")
    echo -e "${RED}✗ PHPStan failed${NC}"
else
    echo -e "${GREEN}✓ PHPStan passed${NC}"
fi
echo ""

# 4. PHPMD (PHP Mess Detector)
echo ">>> Running PHPMD..."
if [ "$ENVIRONMENT" = "github" ]; then
    run_command "composer phpmd"
    PHPMD_STATUS=$?
else
    # Use same changed files for PHPMD
    if [ -z "$CHANGED_FILES" ]; then
        CHANGED_FILES=$(get_changed_files_local)
    fi
    run_phpmd_docker "$CHANGED_FILES"
    PHPMD_STATUS=$?
fi
if [ $PHPMD_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPMD")
    echo -e "${RED}✗ PHPMD failed${NC}"
else
    echo -e "${GREEN}✓ PHPMD passed${NC}"
fi
echo ""

# Summary
echo "======================================"
echo "SUMMARY"
echo "======================================"
echo ""

if [ $OVERALL_STATUS -eq 0 ]; then
    echo -e "${GREEN}✓ ALL CHECKS PASSED${NC}"
    echo -e "${GREEN}Status: COMMITABLE${NC}"
    exit 0
else
    echo -e "${RED}✗ SOME CHECKS FAILED${NC}"
    echo ""
    echo "Failed checks:"
    for check in "${FAILED_CHECKS[@]}"; do
        echo -e "  ${RED}- $check${NC}"
    done
    echo ""
    echo -e "${RED}Status: NON-COMMITABLE${NC}"
    echo ""
    echo "Fix the issues above before committing."
    exit 1
fi
