// Authored test double. This is not a vendor SDK header or ABI fixture.
#pragma once
#include <map>
#include <stdexcept>
#include <string>
#include <vector>
#include <functional>
namespace mgr_access { static const int AccessAdmin = 29; static const int lvAdmin = 29; }
namespace isp_api {
class Session;
class Action;
class Event;
inline std::vector<Action*>& test_actions() { static std::vector<Action*> v; return v; }
inline std::vector<Event*>& test_events() { static std::vector<Event*> v; return v; }
inline bool& test_event_failure() {static bool v=false;return v;}
inline std::function<void()>& test_event_hook() {static std::function<void()> v;return v;}
class Action {
    std::string name_;
public:
    int access;
    Action(const std::string& name, int mask): name_(name), access(mask) { test_actions().push_back(this); }
    virtual ~Action() {}
    std::string name() const { return name_; }
    virtual void Execute(Session&) const = 0;
};
class Authen {
public:
    std::string actor = "fictional-admin";
    int role = 29;
    std::string name() const { return actor; }
    int level() const { return role; }
};
class Session {
public:
    Authen auth;
    const Action& action;
    std::map<std::string, std::string> params;
    explicit Session(const Action& a): action(a) {}
    std::string Param(const std::string& key) const {
        auto item = params.find(key); return item == params.end() ? "" : item->second;
    }
};
class Event {
public:
    enum Priority { epBefore, epAfter };
    std::string action, name;
    Priority priority;
    Event(const std::string& a, const std::string& n, Priority p): action(a), name(n), priority(p) {
        if(test_event_hook()) test_event_hook()();
        if(test_event_failure()) throw std::runtime_error("fictional event registration failure");
        test_events().push_back(this);
    }
    virtual ~Event() {}
    virtual void AfterExecute(Session&) const {}
    virtual void Final(Session&) const {}
};
inline bool HasAction(const std::string& name) {
    for (auto* a: test_actions()) if (a->name() == name) return true;
    return false;
}
}
