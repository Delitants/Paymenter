// Authored C API boundary double. Never source/native database acceptance.
#pragma once
#include <string>
#include <vector>
#include <cstdio>
#include <cstring>
#include <sys/stat.h>
typedef char my_bool;
enum mysql_option {MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT,
    MYSQL_OPT_PROTOCOL,MYSQL_OPT_LOCAL_INFILE,MYSQL_OPT_RECONNECT};
enum {MYSQL_PROTOCOL_SOCKET=2};
struct MYSQL {unsigned server_status=2;std::string query;unsigned options=0;};
struct MYSQL_RES {std::vector<std::vector<std::string>> rows;size_t cursor=0;std::vector<char*> values;std::vector<unsigned long> lengths;};
typedef char** MYSQL_ROW;
static std::string mysql_test_mode,mysql_test_credential;
inline MYSQL* mysql_init(MYSQL*) {return new MYSQL;}
inline void mysql_close(MYSQL* db){delete db;}
inline int mysql_options(MYSQL* db,mysql_option option,const void* value) {
    unsigned v=option==MYSQL_OPT_RECONNECT?static_cast<unsigned>(*static_cast<const my_bool*>(value)):*static_cast<const unsigned*>(value);
    unsigned wanted=(option==MYSQL_OPT_LOCAL_INFILE || option==MYSQL_OPT_RECONNECT)?0:2;
    if(v!=wanted)return 1;
    db->options|=1u<<option;return 0;
}
inline MYSQL* mysql_real_connect(MYSQL* db,const char* host,const char* user,const char* password,const char* schema,unsigned port,const char* socket,unsigned long flags) {
    if(mysql_test_mode=="connect-failure" || db->options!=63 || std::string(host)!="localhost" || std::string(user)!="root" ||
        std::string(password)!="fictional-secret" || std::string(schema)!="qa" || std::string(socket)!="/fictional.sock" || port || flags)return nullptr;
    if(mysql_test_mode=="credential-change") {chmod(mysql_test_credential.c_str(),0600);FILE* f=fopen(mysql_test_credential.c_str(),"w");if(f){fputs("[client]\npassword=changed\n",f);fclose(f);}}
    return db;
}
inline int mysql_autocommit(MYSQL*,my_bool value){return value==1?0:1;}
inline unsigned long mysql_thread_id(MYSQL*){return 12;}
inline int mysql_real_query(MYSQL* db,const char* q,unsigned long n) {
    db->query.assign(q,n);
    if(mysql_test_mode=="query-failure")return 1;
    if(db->query=="SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")return 0;
    if(db->query=="SELECT CONNECTION_ID(),@@session.tx_isolation,@@session.autocommit,DATABASE(),@@socket" ||
       db->query.find("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES ")==0 ||
       db->query.find("SELECT 'present' FROM information_schema.")==0 || db->query.find("SHOW CREATE TABLE paymenter_qa_")==0 ||
       db->query.find("SELECT 'business',run_id,case_id,CAST(amount AS CHAR),facts_hash ")==0)return 0;
    return 1;
}
inline MYSQL_RES* mysql_store_result(MYSQL* db) {
    auto r=new MYSQL_RES;
    if(db->query.find("SELECT CONNECTION_ID()")==0) {
        r->rows={{"12","READ-COMMITTED","1","qa",mysql_test_mode=="wrong-socket"?"/other.sock":"/fictional.sock"}};
        if(mysql_test_mode=="active-transaction")db->server_status=3;
    } else if(db->query.find("SHOW CREATE TABLE ")==0) {
        std::string table=db->query.substr(18);r->rows={{table,mysql_test_mode=="audit-schema-changed"?"changed schema":"fictional "+std::string(table=="paymenter_qa_business_v1"?"business":"journal")+" schema"}};
    } else if(db->query.find("SELECT 'present'")==0) {
        if(mysql_test_mode=="audit-trigger" && db->query.find("TRIGGERS")!=std::string::npos)r->rows={{"present"}};
    } else if(db->query.find("SELECT TABLE_NAME")==0)
        r->rows={{"paymenter_qa_business_v1","InnoDB"},{"paymenter_qa_journal_v1","InnoDB"}};
    else if(mysql_test_mode!="audit-empty") r->rows={{"business",std::string(64,'a'),"F1","0.0100",std::string(64,'c')},{"journal",std::string(64,'a'),"F1","F1",std::string(64,'c')}};
    return r;
}
inline unsigned mysql_num_fields(MYSQL_RES* r){return r->rows.empty()?5:r->rows[0].size();}
inline unsigned long long mysql_num_rows(MYSQL_RES* r){return r->rows.size();}
inline MYSQL_ROW mysql_fetch_row(MYSQL_RES* r) {
    if(r->cursor==r->rows.size())return nullptr;
    r->values.clear();r->lengths.clear();
    for(auto& s:r->rows[r->cursor++]) {r->values.push_back(const_cast<char*>(s.c_str()));r->lengths.push_back(s.size());}
    return r->values.data();
}
inline unsigned long* mysql_fetch_lengths(MYSQL_RES* r){return r->lengths.data();}
inline unsigned mysql_errno(MYSQL*){return 0;}
inline void mysql_free_result(MYSQL_RES* r){delete r;}
