<?php

namespace Paymenter\Extensions\Servers\DNSmanager;

use App\Attributes\ExtensionMeta;
use Paymenter\Extensions\Servers\ISPmanager\ISPmanager;

#[ExtensionMeta(name: 'DNSmanager', description: 'Existing DNS account status; zone and lifecycle integration pending', version: '0.1.0', author: 'Paymenter Community')]
class DNSmanager extends ISPmanager {}
