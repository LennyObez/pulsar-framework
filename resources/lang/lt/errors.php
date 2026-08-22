<?php

declare(strict_types=1);

return [
    // Navigation actions
    'go_back' => 'Go back',
    'go_home' => 'Return home',
    'contact_support' => 'Contact support',
    'refresh_page' => 'Refresh page',
    'try_again' => 'Try again',
    'sign_in' => 'Sign in',
    'search' => 'Search',
    'search_placeholder' => 'Search for something...',
    'popular_links' => 'Popular links',
    'retry_in' => 'Retry in {seconds} seconds',

    // 400 Bad Request
    '400.title' => 'Bad Request',
    '400.heading' => 'Something went wrong with your request',
    '400.description' => 'The server could not understand the request due to malformed syntax or invalid parameters. Please check your input and try again.',

    // 401 Unauthorized
    '401.title' => 'Unauthorized',
    '401.heading' => 'Authentication required',
    '401.description' => 'You need to sign in to access this resource. If you believe this is an error, please contact support.',

    // 403 Forbidden
    '403.title' => 'Forbidden',
    '403.heading' => 'Access denied',
    '403.description' => 'You do not have permission to access this resource. If you believe you should have access, please contact your administrator.',

    // 404 Not Found
    '404.title' => 'Page Not Found',
    '404.heading' => 'We could not find that page',
    '404.description' => 'The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.',

    // 405 Method Not Allowed
    '405.title' => 'Method Not Allowed',
    '405.heading' => 'This action is not supported',
    '405.description' => 'The request method used is not supported for this resource. Please try a different approach or go back to the previous page.',

    // 408 Request Timeout
    '408.title' => 'Request Timeout',
    '408.heading' => 'The request took too long',
    '408.description' => 'The server timed out waiting for the request. This can happen with slow connections or large uploads. Please try again.',

    // 413 Payload Too Large
    '413.title' => 'Payload Too Large',
    '413.heading' => 'The file or data is too large',
    '413.description' => 'The request was larger than the server is configured to accept. Please reduce the size of your upload and try again.',

    // 419 Session Expired
    '419.title' => 'Session Expired',
    '419.heading' => 'Your session has expired',
    '419.description' => 'Your session token has expired or is invalid. This often happens when a page has been open for too long. Please refresh the page to continue.',

    // 422 Unprocessable Entity
    '422.title' => 'Validation Error',
    '422.heading' => 'The submitted data could not be processed',
    '422.description' => 'The server understood your request but was unable to process the contained data. Please review your input and correct any errors.',

    // 429 Too Many Requests
    '429.title' => 'Too Many Requests',
    '429.heading' => 'Slow down, please',
    '429.description' => 'You have sent too many requests in a given amount of time. Please wait before trying again.',

    // 500 Internal Server Error
    '500.title' => 'Server Error',
    '500.heading' => 'Something went wrong on our end',
    '500.description' => 'An unexpected error occurred while processing your request. Our team has been notified. Please try again in a few moments.',

    // 502 Bad Gateway
    '502.title' => 'Bad Gateway',
    '502.heading' => 'Upstream service error',
    '502.description' => 'The server received an invalid response from an upstream server. This is usually temporary. Please try again shortly.',

    // 503 Service Unavailable
    '503.title' => 'Service Unavailable',
    '503.heading' => 'We will be back soon',
    '503.description' => 'The service is temporarily unavailable for maintenance. We are working to restore it as quickly as possible.',
    '503.maintenance' => 'Scheduled maintenance in progress',
    '503.estimated_return' => 'Estimated return: {time}',

    // 504 Gateway Timeout
    '504.title' => 'Gateway Timeout',
    '504.heading' => 'The upstream server did not respond',
    '504.description' => 'The server did not receive a timely response from an upstream server. Please try again in a few moments.',

    // Generic fallbacks
    '4xx.title' => 'Client Error',
    '4xx.heading' => 'Request error',
    '4xx.description' => 'The request could not be completed. Please check your input and try again.',
    '5xx.title' => 'Server Error',
    '5xx.heading' => 'Something went wrong',
    '5xx.description' => 'An error occurred on the server. Please try again later.',
];
