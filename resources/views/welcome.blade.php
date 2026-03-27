<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DKA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Open Sans', sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            min-height: 100vh;
            background: #fff;
            color: #222;
        }

        .text-lg { font-size: 24pt; }
        .text-md { font-size: 18pt; }
        .text-sm { font-size: 14pt; }
    </style>
</head>
<body>
    <div class="text-{{ $size ?? 'md' }}">{{ $text ?? '' }}</div>
    <img src="storage/dka.png" style="max-width:200px">
    <div class="text-lg">
        Domain Key Authority
    </div>
    <br>
    <div class="text-md">
        @if(config('dka.target_domain')=='*')
            This website is configured locally to be the root DKA (rDKA).
        @else
            This website is configured locally to be the DKA of {{ config('dka.target_domain') }}.
        @endif
    </div>
    <br>
    <div class="text-sm">
        The corresponding domain's DKA designation: {{ dns_text() ? trim(dns_text()) : 'NULL' }}
    </div>
    <div class="text-md">
        {{-- This designation is {{ dns_designation_correct() ? 'CORRECT' : 'INCORRECT'}} --}}
    </div>

</body>
</html>
