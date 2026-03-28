/**
 * Global teardown - Cleanup after tests
 */

const fs = require('fs');

async function globalTeardown() {
    // Clean up auth state file
    try {
        if (fs.existsSync('.auth.json')) {
            fs.unlinkSync('.auth.json');
        }
    } catch (e) {
        // Ignore cleanup errors
    }
    console.log('🧹 Cleanup complete\n');
}

module.exports = globalTeardown;
