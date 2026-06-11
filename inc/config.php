<?php

$config = [
    'db' => [
        'events' => [
            'dbhost' => '127.0.0.1',
            'dbname' => 'event_mgmt',
            'dbuser' => 'root',
            'dbpass' => '',
        ],
        'local' => [ // Added for Auth class which uses 'local'
            'dbhost' => '127.0.0.1',
            'dbname' => 'event_mgmt',
            'dbuser' => 'root',
            'dbpass' => '',
        ],
        'otrs' => [
            'dbhost' => '127.0.0.1',
            'dbname' => 'otrs',
            'dbuser' => 'root',
            'dbpass' => '',
        ]
    ],
    'otrs' => [
        'ticket_link' => 'https://otrs.example.com/otrs/index.pl?Action=AgentTicketZoom;TicketID=',
        'change_link' => 'https://otrs.example.com/otrs/index.pl?Action=AgentITSMChangeZoom;ChangeID=',
    ],
    'azure' => [
        'clientId'     => 'YOUR_CLIENT_ID',
        'clientSecret' => 'YOUR_CLIENT_SECRET',
        'redirectUri'  => 'http://localhost:8080/callback.php',
        'tenantId'     => 'common'
    ],
    'api' => [
        'key' => 'DEV-KEY-12345',
        'otrs' => [
            'url' => 'https://otrs.example.com/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST',
            'key' => 'OTRS_API_KEY',
            'default' => [
                'type' => 'Unclassified',
                'queue' => 'Misc',
                'priority' => '3 normal',
                'state' => 'new',
                'userID' => 1
            ]
        ]
    ]
];
