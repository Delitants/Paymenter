#ifndef PAYMENTER_LEGACY_JOURNAL_WRITER_HPP
#define PAYMENTER_LEGACY_JOURNAL_WRITER_HPP

#include <stdexcept>
#include <string>

namespace mgr_db {
class Connection;
}

namespace paymenter_legacy {

struct JournalObservation {
    std::string effect_key;
    std::string economic_sha256;
    std::string signing_key_id;
    std::string payload;
};

class JournalRefused : public std::runtime_error {
public:
    using std::runtime_error::runtime_error;
};

// Bind this to the already active BILLmanager transaction before its payment
// write. A commit or rollback destroys the marker even on the same connection.
// The caller owns the transaction and must abort it if recording fails.
class JournalTransaction {
public:
    explicit JournalTransaction(mgr_db::Connection& connection);
    JournalTransaction(const JournalTransaction&) = delete;
    JournalTransaction& operator=(const JournalTransaction&) = delete;

    std::string append(const JournalObservation& observation);

private:
    mgr_db::Connection& connection_;
    std::string server_connection_id_;
    std::string marker_;
    bool used_ = false;
};

}

#endif
