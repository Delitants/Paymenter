#include "journal_writer.hpp"
#include <mgr/mgrdb.h>

namespace paymenter_legacy {
namespace {

bool digest(const std::string& value) {
    return value.size() == 64 && value.find_first_not_of("0123456789abcdef") == std::string::npos;
}

bool decimal_id(const std::string& value) {
    return !value.empty() && value.size() <= 20 && value.front() != '0' &&
           value.find_first_not_of("0123456789") == std::string::npos;
}

bool key_id(const std::string& value) {
    const std::string letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
    return !value.empty() && value.size() <= 128 && letters.find(value.front()) != std::string::npos &&
           value.find_first_not_of(letters + "0123456789._-") == std::string::npos;
}

std::string hex_bytes(const std::string& value) {
    const char digits[] = "0123456789ABCDEF";
    std::string encoded;
    encoded.reserve(value.size() * 2);
    for (unsigned char byte : value) {
        encoded += digits[byte >> 4];
        encoded += digits[byte & 15];
    }
    return encoded;
}

void require_transaction(mgr_db::Connection& connection) {
    if (connection.TransactionCount() < 1) {
        throw JournalRefused("The original native transaction is required.");
    }
}

void require_original_transaction(mgr_db::Connection& connection, const std::string& expected_id) {
    require_transaction(connection);
    auto current = connection.Query("SELECT CONNECTION_ID(), @@in_transaction");
    if (!current || current->Eof() || current->AsString(static_cast<size_t>(0)) != expected_id ||
        current->AsString(static_cast<size_t>(1)) != "1") {
        throw JournalRefused("The original native connection or transaction changed.");
    }
}

}

JournalTransaction::JournalTransaction(mgr_db::Connection& connection) : connection_(connection) {
    require_transaction(connection_);
    auto original = connection_.Query("SELECT CONNECTION_ID(), @@in_transaction, REPLACE(UUID(),'-','')");
    if (!original || original->Eof() || original->AsString(static_cast<size_t>(1)) != "1") {
        throw JournalRefused("The original native transaction is required.");
    }
    server_connection_id_ = original->AsString(static_cast<size_t>(0));
    const auto nonce = original->AsString(static_cast<size_t>(2));
    original.reset();
    if (!decimal_id(server_connection_id_) || nonce.size() != 32 ||
        nonce.find_first_not_of("0123456789abcdef") != std::string::npos) {
        throw JournalRefused("The native journal transaction identity is invalid.");
    }
    marker_ = "paymenter_legacy_" + nonce;
    connection_.Query("SAVEPOINT " + marker_);
    require_original_transaction(connection_, server_connection_id_);
}

std::string JournalTransaction::append(const JournalObservation& observation) {
    if (used_ || !digest(observation.effect_key) ||
        !digest(observation.economic_sha256) || !key_id(observation.signing_key_id) ||
        observation.payload.empty() || observation.payload.size() > 65536) {
        throw JournalRefused("The validated journal observation is malformed.");
    }
    used_ = true;
    require_original_transaction(connection_, server_connection_id_);
    try {
        connection_.Query("RELEASE SAVEPOINT " + marker_);
    } catch (const mgr_err::Error&) {
        throw JournalRefused("The original native transaction marker is unavailable.");
    }

    // Each write checks the original session inside the statement. A driver
    // reconnect between two calls must never create an independent receipt.
    connection_.Query("INSERT INTO paymenter_legacy_effects (effect_key, economic_sha256) "
                     "SELECT ?, ? FROM DUAL WHERE CONNECTION_ID()=? AND @@in_transaction=1 "
                     "ON DUPLICATE KEY UPDATE effect_key=paymenter_legacy_effects.effect_key",
                      observation.effect_key, observation.economic_sha256, server_connection_id_);
    auto effect = connection_.Query("SELECT economic_sha256 FROM paymenter_legacy_effects "
                                   "WHERE effect_key=? AND CONNECTION_ID()=? AND @@in_transaction=1 FOR UPDATE",
                                    observation.effect_key, server_connection_id_);
    if (!effect || effect->Eof() || effect->AsString(static_cast<size_t>(0)) != observation.economic_sha256) {
        throw JournalRefused("The original effect conflicts with the journal observation.");
    }
    effect.reset();

    const auto payload = hex_bytes(observation.payload);
    connection_.Query("INSERT INTO paymenter_legacy_journal (effect_key, payload_sha256, key_id, payload) "
                     "SELECT ?, SHA2(body,256), ?, body FROM (SELECT UNHEX(?) AS body) AS submitted "
                     "WHERE CONNECTION_ID()=? AND @@in_transaction=1 "
                     "ON DUPLICATE KEY UPDATE payload_sha256=paymenter_legacy_journal.payload_sha256",
                      observation.effect_key, observation.signing_key_id, payload, server_connection_id_);
    auto stored = connection_.Query("SELECT id, effect_key, key_id, HEX(payload) FROM paymenter_legacy_journal "
                                   "WHERE payload_sha256=SHA2(UNHEX(?),256) AND CONNECTION_ID()=? "
                                   "AND @@in_transaction=1 FOR UPDATE",
                                    payload, server_connection_id_);
    if (!stored || stored->Eof() || stored->AsString(static_cast<size_t>(1)) != observation.effect_key ||
        stored->AsString(static_cast<size_t>(2)) != observation.signing_key_id ||
        stored->AsString(static_cast<size_t>(3)) != payload) {
        throw JournalRefused("The retained journal bytes or signer conflict.");
    }
    const auto journal_id = stored->AsString(static_cast<size_t>(0));
    stored.reset();
    if (!decimal_id(journal_id)) {
        throw JournalRefused("The retained journal identity is invalid.");
    }
    require_original_transaction(connection_, server_connection_id_);
    return journal_id;
}

}
