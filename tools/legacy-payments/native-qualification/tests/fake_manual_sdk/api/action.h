// Isolated authored SDK boundary. No vendor implementation or DB semantics.
#pragma once
#include <functional>
#include <map>
#include <string>
#include <vector>
#include <stdexcept>
using StringVector=std::vector<std::string>;
namespace mgr_access {static const int AccessAdmin=29;static const int lvAdmin=29;}
namespace mgr_xml {struct Xml {std::string bytes;Xml(std::string b=""):bytes(b){}};}
namespace isp_api {
using mgr_xml::Xml;
class Session;class Action;
inline std::vector<Action*>& test_actions(){static std::vector<Action*> v;return v;}
inline bool& test_action_failure(){static bool v=false;return v;}
inline std::function<void()>& test_action_hook(){static std::function<void()> v;return v;}
class Action {
 std::string name_;
public:
 int access;
 Action(const std::string& n,int a):name_(n),access(a){if(test_action_failure())throw std::runtime_error("authored registration failure");test_actions().push_back(this);if(test_action_hook())test_action_hook()();}
 virtual ~Action(){};
 std::string name()const{return name_;}
 virtual void Execute(Session&)const=0;
};
class Authen {public:std::string actor="fictional-admin";int role=29;std::string name()const{return actor;}int level()const{return role;}};
class BaseConnection {const Session* owner_;public:explicit BaseConnection(const Session* o):owner_(o){}void GetParams(StringVector&)const;};
class Session {
public:
 Authen auth;const Action& action;std::map<std::string,std::string> params;BaseConnection conn;Xml xml;
 explicit Session(const Action& a):action(a),conn(this){}
 std::string Param(const std::string& k)const{auto p=params.find(k);return p==params.end()?"":p->second;}
};
inline void BaseConnection::GetParams(StringVector& out)const{for(const auto& v:owner_->params)out.push_back(v.first);}
inline bool HasAction(const std::string& n){for(auto a:test_actions())if(a->name()==n)return true;return false;}
Xml InternalCall(const Session&,const std::string&,const std::string&);
Xml InternalCall(const std::string&,const std::string&);
Authen Authenticate();
}
