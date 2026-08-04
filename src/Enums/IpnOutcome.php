<?php

// src/Enums/IpnOutcome.php
enum IpnOutcome
{
    case Processed;
    case InvalidSignature;
    case Duplicate;
    case Stale;
    case HandlerFailed;
    case Disabled;
}
