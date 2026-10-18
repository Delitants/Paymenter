// Authored diagnostic-query double; no SQL engine or transaction semantics.
#pragma once
#include <memory>
#include <string>
namespace mgr_db {
class Query {
    std::string value_;
    bool eof_ = false;
public:
    explicit Query(std::string value): value_(value) {}
    bool Eof() const { return eof_; }
    std::string AsString(size_t) { return value_; }
    bool Next() { eof_=true; return false; }
};
using QueryPtr = std::shared_ptr<Query>;
}
