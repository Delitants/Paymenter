// Exact fixed DDL only. Never execute inside an SDK handler/startup.
#pragma once
#include <array>
#include <string>
namespace paymenter_qa {
inline std::array<std::string,2> qa_resource_ddl() {
    return {{
        "CREATE TABLE paymenter_qa_business_v1 ( run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, case_id CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, amount DECIMAL(18,4) NOT NULL, facts_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (run_id, case_id) ) ENGINE=InnoDB;",
        "CREATE TABLE paymenter_qa_journal_v1 ( run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, case_id CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, business_case_id CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, facts_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (run_id, case_id) ) ENGINE=InnoDB;"
    }};
}
}
