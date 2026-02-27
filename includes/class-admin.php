<?php
// Include other necessary dependencies before this code

// Existing UI components

// Function to remove "Run Single" UI blocks
function remove_run_single_blocks() {
    // Logic to identify and remove Hostfully section
    if (isset($hostfully_section)) {
        unset($hostfully_section['run_single']); // Example key to identify the "Run Single" block
    }

    // Logic to identify and remove Loggia section
    if (isset($loggia_section)) {
        unset($loggia_section['run_single']); // Example key to identify the "Run Single" block
    }
}

// Call the function at an appropriate place in your flowemove_run_single_blocks();

// The rest of your existing code follows...