-- Explicitly admitted DDL only. Never execute from a handler or module startup.
-- Existing names are collisions: no IF NOT EXISTS, replacement or reconciliation.
CREATE TABLE paymenter_qa_business_v1 (
    run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_id CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(18,4) NOT NULL,
    facts_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (run_id, case_id)
) ENGINE=InnoDB;

CREATE TABLE paymenter_qa_journal_v1 (
    run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_id CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    business_case_id CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    facts_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (run_id, case_id)
) ENGINE=InnoDB;
