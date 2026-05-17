<head>
    <style>

body {
    font-family: 'Garamond', serif;
    line-height: 1.5;
    max-width: 800px;
    margin: 40px auto;
    padding: 22px;
}

p {
    text-indent: 1.6em; /* Indent first line of paragraphs */
    font-size: 22px;
    color: black;
}

faq {
    font-family: 'Helvetica Neue', sans-serif;
    text-align: left;
    color: black;
    margin-bottom: 0.5em;
    font-size: 20px;
    font-style: italic;
    font-weight: bold;
}

h1, h2, h3 {
    font-family: 'Helvetica Neue', sans-serif;

    /* margin-top: 2em; */
    margin-bottom: 0.6em;
    color : #396ff8;
}


        div { text-align: left; }

        .text-lg { font-size: 24pt; }
        .text-md { font-size: 18pt; }
        .text-sm { font-size: 14pt; }


a {
    color:#2d8fc0;
    text-decoration: none;
    font-size: 22px;
}

blockquote {
    border-left: 4px solid #ccc;
    padding-left: 20px;
    margin: 1.5em 0;
    font-style: italic;
    color: #555;
}

pre { /* For code blocks */
    background-color: #f4f4f4;
    padding: 15px;
    border-radius: 5px;
    overflow-x: auto;
    font-family: 'Courier New', monospace;
}
</style>
</head>
<center>
<img src="storage/DKA2.png" style="max-width:120px; margin:-20px auto 0px auto">
    <h1>
            Domain Key Authorities: DNS-Designated Public Keys for Email
    </h1>
</center>
<p>
    The Domain Key Authority (DKA) framework is a DNS-anchored public-key distribution mechanism
    for the email-address namespace.
    The framework enables an Internet domain to designate an authoritative
    key service (a domain key authority) that verifies, stores, and distributes
    selector-scoped public keys for email-address identifiers under that domain.
    The result is a decentralized, deterministic, and application-agnostic
    framework for verified public-key discovery that supports incremental deployment
    and cryptographic agility.
</p>

<p>
    Click <a href='https://datatracker.ietf.org/doc/draft-swaminathan-dka-framework/' target='_'>here </a> for a proposed DKA standard
    in an IETF Internet Draft.
</p>

<p>
    Click <a href='/dka-whitepaper/' target='_'>here </a> for the DKA whitepaper that includes
    framework bootstrapping solution.
</p>

<center>
<h1>
The DKA open source site is on github at <br> <a href="https://github.com/kishore032/dka">kishore032/dka. </a> <br>
</h1>
</center>
<h1>
The DKA Demo
</h1>

<div class="text-md" style="text-align: left; margin-left: 6px">
    1. Register a public key in a DKA.
</div>

<div style="text-align: left; margin-left: 20px" class="text-sm">
    Keymail1.com and Keymail2.com are two demo domains that have DNS designated DKAs.<br> <br>
    1. Register an email id at <a href="https://keymail1.com">keymail1.com</a> and login. <br>
    2. From your email account, click '...' on top and click 'Demo Get token', and save and send the composed email. <br>
    3. In a few seconds refresh Incoming Email. You'll see you've received a token from the DKA. <br>
    4. Click on the email with the token to open it. <br>
    5. Click on '...' and choose 'Demo Register Key'. Type "default" for selector. The demo will prepare a JSON string for you to send via email. <br>
    6. Send the email. It will be delivered to the DKA with the token and your public key. <br>
    7. In a few seconds refresh Incoming Email. You'll see you've successfully registered a public key. <br>
    8. To demo the retrieval API, on a browser, type: <br>
        &nbsp;&nbsp;https://dka.keymail1.com/.well-known/dka/lookup?email_address=your-email-address <br>
    9. You should get the public key record you just registered. <br>
</div>
<br>

<div class="text-md" style="text-align: left; margin-left: 6px">
    2. An Application Demo (end-to-end email encryption) <br>
</div>

<div style="text-align: left; margin-left: 20px" class="text-sm">
    1. Register a second email id at keymail2.com. <br>
    2. Send an email from your keymail2 id to your keymail1 id. Since you registered a public key for your keymail1 id, this email will go encrypted (look for lock sign). <br>
    3. Send an email from your keymail1 id to keymail2 id. It will be unencrypted as your keymail2 id does not have a public key yet. <br>
    4. Set up a public key for your keymail2 id as you did for keymail1. <br>
    5. Now email you send from one keymail id to the other will be encrypted and signed (lock and fingerprint icons). <br>
    {{-- 6. You can even send an encrypted message from keymail1 or keymail2 to your external email address of Step 2. <br> --}}
    6. This demo is intended to show how applications can use the public keys in the DKA framework. <br>
<br>
</div>
<br>
<div class="text-md" style="text-align: left; margin-left: 6px">
    3. Register a public key in Fallback DKA (fDKA)
</div>

<div style="text-align: left; margin-left: 20px" class="text-sm">
    1. Login to your regular email account (e.g., gmail). <br>
    2. Send an email with any or no content to dka@dka.keyzero.org (an fDKA standin). <br>
    3. In a few seconds, you'll see you've received an email with a token from keyzero.org. <br>
    4. Compose an email to dka@dka.keyzero.org with the JSON below. Make sure you copy the received token correctly into the token field. <br> <br>
        &nbsp;&nbsp;{"token":"",<br>"public_key":"t2a1lWU2Q4aFB2NUNsUTJUdWFERzFhQVNsSUhFeVVPaEpCaDd3L",
        <br> "selector":"default",
        "metadata":"{\"algorithm\":\"RSA\"}"} <br><br>
    5. Send the email. If you've copied the token correctly, the fDKA will register your public key. <br>
    6. In a few seconds, you'll get a registration message. <br>
    7. To demo the fDKA retrieval API, on a browser, type: <br>
        &nbsp;&nbsp;https://dka.keyzero.org/.well-known/dka/lookup?email_address=your-email-address <br>
    8. You should get the public key record you just registered. <br>
</div>
<br>





