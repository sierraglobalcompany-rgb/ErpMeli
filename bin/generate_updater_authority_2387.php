<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=b7bb9a33607cfe5725a02ac0a96dd0f41019ceeb');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.38.6');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.38.7');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The metadata-only 2.38.6 to 2.38.7 update keeps schema 297. It makes the bounded Sales Audit stage OAuth-aware before claim, treats OAuthRefreshRequiredException as a non-failure only behind exact tenant, dispatch, lease and operation fences, repairs only the exact 2.38.6 false-OAuth error signature, and aborts remaining stages on authority contradictions. It preserves commercial Queue V4 FIFO and the 2.38.6 private-filesystem escrow authority, performs no Mercado Libre writes, creates no scheduler, and never packages mutable production state.');

require __DIR__ . '/generate_updater_authority_2384.php';
