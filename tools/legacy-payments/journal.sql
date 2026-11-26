-- Install only through the separately authorized source journal deployment.
-- The native business writer inserts these first two rows in its transaction.
CREATE TABLE paymenter_legacy_effects (
    effect_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    economic_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (effect_key)
) ENGINE=InnoDB;

CREATE TABLE paymenter_legacy_journal (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    effect_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    key_id VARBINARY(128) NOT NULL,
    payload MEDIUMBLOB NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY (payload_sha256),
    FOREIGN KEY (effect_key) REFERENCES paymenter_legacy_effects (effect_key)
) ENGINE=InnoDB;

-- The dispatcher reads the journal and writes only these two relations.
CREATE TABLE paymenter_legacy_dispatch (
    journal_id BIGINT UNSIGNED NOT NULL,
    signature MEDIUMBLOB NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    completed_attempt INT UNSIGNED NOT NULL DEFAULT 0,
    state ENUM('queued', 'stored_pending', 'quarantined', 'included', 'applied', 'applied_zero', 'expired', 'invalid')
        NOT NULL DEFAULT 'queued',
    next_attempt_at DATETIME NOT NULL,
    acknowledgement BLOB NULL,
    PRIMARY KEY (journal_id),
    KEY (state, next_attempt_at),
    FOREIGN KEY (journal_id) REFERENCES paymenter_legacy_journal (id)
) ENGINE=InnoDB;

CREATE TABLE paymenter_legacy_dispatch_attempts (
    journal_id BIGINT UNSIGNED NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    acknowledgement_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (journal_id, attempt),
    FOREIGN KEY (journal_id) REFERENCES paymenter_legacy_dispatch (journal_id)
) ENGINE=InnoDB;
