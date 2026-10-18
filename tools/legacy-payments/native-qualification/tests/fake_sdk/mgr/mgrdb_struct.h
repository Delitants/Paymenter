// Authored call-recording double. It implements no transactions or database.
#pragma once
#include <mgr/mgrdb.h>
#include <functional>
#include <memory>
#include <stdexcept>
#include <string>
#include <vector>
namespace mgr_db {
struct Connection {};
struct Call { std::string sql; std::vector<std::string> values; };
inline std::vector<Call>& calls() { static std::vector<Call> v; return v; }
inline std::vector<std::string>& reads() { static std::vector<std::string> v; return v; }
inline int& caches() { static int n = 0; return n; }
inline std::function<void()>& on_write() { static std::function<void()> f; return f; }
inline std::function<void()>& on_connection() { static std::function<void()> f; return f; }
class Stmt {
    std::string sql_;
    std::string server_id_;
public:
    Stmt(std::string sql, std::string id): sql_(sql), server_id_(id) {}
    QueryPtr Execute() const { reads().push_back(sql_); return std::make_shared<Query>(server_id_); }
    void Execute(const std::string& a, const std::string& b,
                 const std::string& c, const std::string& d) const {
        calls().push_back({sql_, {a,b,c,d}});
        if (on_write()) on_write()();
    }
};
class Cache {
    Connection connections_[2];
public:
    int connection = 0;
    std::string server_id = "71";
    Cache() { ++caches(); }
    Connection* GetConnection() { if (on_connection()) on_connection()(); return &connections_[connection]; }
    Stmt Prepare(const std::string& sql) { return Stmt(sql,server_id); }
};
}
