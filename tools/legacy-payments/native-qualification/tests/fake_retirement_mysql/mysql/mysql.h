// Strict authored SQL boundary. No database or production state.
#pragma once
#define PAYMENTER_QA_AUTHORED_MYSQL_BOUNDARY 1
#include <string>
#include <vector>
#include <cstring>
#include <functional>
#include <array>
typedef char my_bool;
enum mysql_option {MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT,MYSQL_OPT_PROTOCOL,MYSQL_OPT_LOCAL_INFILE,MYSQL_OPT_RECONNECT};
enum {MYSQL_PROTOCOL_SOCKET=2};
struct MYSQL {unsigned server_status=2,options=0;std::string query;bool auto_commit=true;};
struct MYSQL_RES {std::vector<std::vector<std::string>> rows;unsigned columns=0;size_t cursor=0;std::vector<char*> values;std::vector<unsigned long> lengths;};
typedef char** MYSQL_ROW;
static std::string test_mode;
static bool test_resource_mode=false;
static std::array<bool,2> test_resource_tables{{false,false}};
static unsigned test_creates=0;
static std::function<void(const std::string&)> test_query_hook;
static std::vector<std::string> test_queries;
static unsigned test_connects=0,test_closes=0,test_erases=0,test_commits=0,test_drops=0,test_locks=0;
static unsigned test_limits=0;
static bool test_locked=false,test_rows=true,test_dropped=false;
inline MYSQL* mysql_init(MYSQL*){return new MYSQL;}
inline void mysql_close(MYSQL* db){++test_closes;test_locked=false;delete db;}
inline int mysql_options(MYSQL* db,mysql_option op,const void* value){
    unsigned v=op==MYSQL_OPT_RECONNECT?static_cast<unsigned>(*static_cast<const my_bool*>(value)):*static_cast<const unsigned*>(value);
    if(v!=((op==MYSQL_OPT_RECONNECT || op==MYSQL_OPT_LOCAL_INFILE)?0:2))return 1;
    db->options|=1u<<op;return 0;
}
inline MYSQL* mysql_real_connect(MYSQL* db,const char* host,const char* user,const char* pass,const char* schema,unsigned port,const char* socket,unsigned long flags){
    ++test_connects;if(db->options!=63 || std::string(host)!="localhost" || std::string(user)!="root" || std::string(pass)!="fictional-secret" ||
        std::string(schema)!="qa" || std::string(socket)!="/fictional.sock" || port || flags)return nullptr;
    return db;
}
inline int mysql_autocommit(MYSQL*,my_bool){return 0;}
inline unsigned long mysql_thread_id(MYSQL*){return 12;}
inline int mysql_real_query(MYSQL* db,const char* q,unsigned long size){
    db->query.assign(q,size);test_queries.push_back(db->query);const std::string s=db->query;
    if(test_query_hook)test_query_hook(s);
    if(test_resource_mode && s.find("CREATE TABLE paymenter_qa_")==0){
        const size_t index=s.find("CREATE TABLE paymenter_qa_business_v1")==0?0:1;++test_creates;
        if(test_resource_tables[index] || (index==1 && test_mode=="second-create-failure"))return 1;
        test_resource_tables[index]=true;return 0;
    }
    if(s=="SET SESSION lock_wait_timeout=2" || s=="SET SESSION innodb_lock_wait_timeout=2"){++test_limits;return 0;}
    if(test_mode=="metadata-deadline" && s.find("information_schema.")!=std::string::npos && test_limits!=2)return 1;
    if(s=="SET SESSION autocommit=0"){db->auto_commit=false;db->server_status=0;return 0;}
    if(s=="SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED" || s=="SET SESSION lock_wait_timeout=2" || s=="SET SESSION innodb_lock_wait_timeout=2")return 0;
    if(s=="LOCK TABLES paymenter_qa_business_v1 WRITE,paymenter_qa_journal_v1 WRITE"){
        ++test_locks;if(test_mode=="lock-timeout")return 1;test_locked=true;return 0;}
    if(s.find("DELETE FROM ")==0){
        ++test_erases;if(!test_locked)return 1;
        if(s.find("run_id='"+std::string(64,'a')+"' AND case_id='F1' AND facts_hash='"+std::string(64,'c')+"'")==std::string::npos)return 1;
        if(test_erases%2==1 && s.find("DELETE FROM paymenter_qa_journal_v1 ")==0 && s.find(" AND business_case_id='F1'")!=std::string::npos)return 0;
        if(test_erases%2==0 && s.find("DELETE FROM paymenter_qa_business_v1 ")==0 && s.find(" AND amount=0.0100")!=std::string::npos)return test_mode=="delete-second-failure"?1:0;
        return 1;
    }
    if(s=="COMMIT AND NO CHAIN NO RELEASE"){++test_commits;if(test_mode=="commit-failure")return 1;test_rows=test_mode=="remaining-rows";db->server_status=0;return 0;}
    if(s=="DROP TABLE paymenter_qa_business_v1,paymenter_qa_journal_v1"){
        ++test_drops;if(!test_locked || test_rows || test_mode=="drop-failure")return 1;test_dropped=test_mode!="drop-not-absent" && test_mode!="cleanup-partial-drop";
        if(test_resource_mode){test_resource_tables[0]=false;test_resource_tables[1]=test_mode=="cleanup-partial-drop";}return 0;}
    if(s=="ROLLBACK AND NO CHAIN NO RELEASE")return 0;
    if(s=="UNLOCK TABLES"){test_locked=false;return 0;}
    if(s.find("SELECT ")==0 || s=="SHOW CREATE TABLE paymenter_qa_business_v1" || s=="SHOW CREATE TABLE paymenter_qa_journal_v1")return 0;
    return 1;
}
inline MYSQL_RES* mysql_store_result(MYSQL* db){
    auto r=new MYSQL_RES;const std::string& q=db->query;
    if(q=="SELECT CONNECTION_ID(),@@session.autocommit,DATABASE(),@@socket"){
        r->columns=4;r->rows={{test_mode=="connection-change" && test_locked?"13":"12",db->auto_commit?"1":"0",test_mode=="wrong-database"?"other":"qa",test_mode=="wrong-socket"?"/other":"/fictional.sock"}};
        if(test_mode=="dirty-session")db->server_status|=1;
    }else if(q.find("SELECT CONNECTION_ID(),@@session.tx_isolation")==0){r->columns=5;r->rows={{"12","READ-COMMITTED","1","qa","/fictional.sock"}};
    }else if(q.find("SELECT @@session.lock_wait_timeout")==0){r->columns=3;r->rows={{test_mode=="timeout-not-set"?"0":"2","2",test_mode=="table-locks-disabled"?"0":"1"}};
    }else if(q.find("SELECT TABLE_NAME,ENGINE,TABLE_TYPE")==0){r->columns=3;r->rows={{"paymenter_qa_business_v1",test_mode=="wrong-engine"?"MyISAM":"InnoDB",test_mode=="view"?"VIEW":"BASE TABLE"},{"paymenter_qa_journal_v1","InnoDB","BASE TABLE"}};
    }else if(q.find("information_schema.TRIGGERS")!=std::string::npos){r->columns=1;if(test_mode=="trigger" || test_mode=="create-trigger")r->rows={{"present"}};
    }else if(q.find("information_schema.KEY_COLUMN_USAGE")!=std::string::npos){r->columns=1;if(test_mode=="incoming-fk" || test_mode=="outgoing-fk" || test_mode=="create-link")r->rows={{"present"}};
    }else if(q.find("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE")==0){r->columns=6;
        for(const auto& table:{"paymenter_qa_business_v1","paymenter_qa_journal_v1"}){
            r->rows.push_back({table,"run_id","char(64)","ascii_bin","NO","PRI"});r->rows.push_back({table,"case_id","char(2)","ascii_bin","NO","PRI"});
            if(std::string(table)=="paymenter_qa_business_v1")r->rows.push_back({table,"amount","decimal(18,4)","none","NO",""});
            else r->rows.push_back({table,"business_case_id","char(2)","ascii_bin","NO",""});
            r->rows.push_back({table,"facts_hash","char(64)","ascii_bin","NO",""});}
        if(test_mode=="create-schema-mismatch")r->rows[0][2]="char(63)";
    }else if(q.find("SHOW CREATE TABLE ")==0){r->columns=2;r->rows={{test_mode=="schema-wrong-name"?"wrong":q.substr(18),q.substr(18)+(test_mode=="changed-delete-schema" || test_mode=="changed-drop-schema"?" changed definition":" definition")}};
    }else if(q.find("SELECT 'business'")==0){r->columns=5;if(test_rows)r->rows={{"business",std::string(64,'a'),"F1","0.0100",std::string(64,'c')},{"journal",std::string(64,'a'),"F1","F1",std::string(64,'c')}};
        if(test_mode=="rows-overflow")while(r->rows.size()<33)r->rows.push_back(r->rows[0]);
    }else if(q.find("SELECT TABLE_NAME FROM information_schema.TABLES")==0){r->columns=1;if(test_resource_mode){if(test_resource_tables[0])r->rows.push_back({"paymenter_qa_business_v1"});if(test_resource_tables[1])r->rows.push_back({"paymenter_qa_journal_v1"});}
        else if(!test_dropped)r->rows={{"paymenter_qa_business_v1"},{"paymenter_qa_journal_v1"}};
    }else {r->columns=5;}
    return r;
}
inline unsigned mysql_num_fields(MYSQL_RES* r){return r->columns;}
inline unsigned long long mysql_num_rows(MYSQL_RES* r){return r->rows.size();}
inline MYSQL_ROW mysql_fetch_row(MYSQL_RES* r){
    if(r->cursor==r->rows.size())return nullptr;
    r->values.clear();r->lengths.clear();
    for(auto& s:r->rows[r->cursor++]){r->values.push_back(const_cast<char*>(s.c_str()));r->lengths.push_back(s.size());}
    if(test_mode=="row-null" && r->columns==5 && !r->values.empty())r->values[0]=nullptr;
    return r->values.data();
}
inline unsigned long* mysql_fetch_lengths(MYSQL_RES* r){return r->lengths.data();}
inline unsigned mysql_errno(MYSQL*){return 0;}
inline void mysql_free_result(MYSQL_RES* r){delete r;}
