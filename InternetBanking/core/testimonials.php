<?php

function rb_testimonial_input_error($quote_text, $display_name, $attribution, $status, $sort_order, $consent_confirmed)
{
    if (!is_string($quote_text) || $quote_text === '' || strlen($quote_text) > 1200
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $quote_text)) {
        return 'Enter a testimonial of up to 1,200 characters.';
    }
    if (!is_string($display_name) || $display_name === '' || strlen($display_name) > 80
        || preg_match('/[\x00-\x1F\x7F]/', $display_name)) {
        return 'Enter a public display name of up to 80 characters.';
    }
    if (!is_string($attribution) || strlen($attribution) > 120 || preg_match('/[\x00-\x1F\x7F]/', $attribution)) {
        return 'Attribution must be 120 characters or fewer.';
    }
    if (!in_array($status, array('draft', 'published'), true)) {
        return 'Choose a valid testimonial publication status.';
    }
    if (!is_int($sort_order) || $sort_order < 0 || $sort_order > 9999) {
        return 'Choose a display order from 0 to 9999.';
    }
    if ($consent_confirmed !== true) {
        return 'Confirm that the customer approved public use of this testimonial and attribution.';
    }
    return '';
}
