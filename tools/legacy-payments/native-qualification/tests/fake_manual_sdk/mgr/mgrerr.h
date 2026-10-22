// Authored error-type boundary only; not vendor error serialization or rollback.
#pragma once
#include <stdexcept>
#include <string>
namespace mgr_err {
class Error: public std::runtime_error {
    std::string type_;
public:
    explicit Error(const std::string& type): std::runtime_error(type), type_(type) {}
    const std::string& type() const { return type_; }
};
}
