// C API boundary double only. No socket or native database is opened.
#pragma once
#include "manual_financial_reader_fixture.hpp"
#include <cstdio>
#include <sys/stat.h>
typedef char my_bool;
enum mysql_option {MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT,MYSQL_OPT_PROTOCOL,MYSQL_OPT_LOCAL_INFILE,MYSQL_OPT_RECONNECT};
enum {MYSQL_PROTOCOL_SOCKET=2};
struct MYSQL {unsigned server_status=2,options=0,queries=0;std::string query;};
struct MYSQL_RES {paymenter_qa::ReadRows rows;size_t cursor=0;std::vector<char*> values;std::vector<unsigned long> lengths;};
typedef char** MYSQL_ROW;
static std::string manual_mysql_mode,manual_mysql_root;
static unsigned manual_mysql_connections=0;
inline MYSQL* mysql_init(MYSQL*){return new MYSQL;}
inline void mysql_close(MYSQL* db){delete db;}
inline int mysql_options(MYSQL* db,mysql_option option,const void* value){unsigned n=option==MYSQL_OPT_RECONNECT?*static_cast<const my_bool*>(value):*static_cast<const unsigned*>(value);if(n!=((option==MYSQL_OPT_LOCAL_INFILE || option==MYSQL_OPT_RECONNECT)?0:2))return 1;db->options|=1u<<option;return 0;}
inline MYSQL* mysql_real_connect(MYSQL* db,const char* host,const char* user,const char* pass,const char* schema,unsigned port,const char* socket,unsigned long flags){++manual_mysql_connections;if(manual_mysql_mode=="connect-failure" || db->options!=63 || std::string(host)!="localhost" || std::string(user)!="root" || std::string(pass)!="fictional-secret" || std::string(schema)!="qa" || std::string(socket)!="/fictional.sock" || port || flags)return nullptr;return db;}
inline int mysql_autocommit(MYSQL*,my_bool v){return v==1?0:1;}
inline unsigned long mysql_thread_id(MYSQL*){return 12;}
inline int mysql_real_query(MYSQL* db,const char* q,unsigned long n){db->query.assign(q,n);++db->queries;if(manual_mysql_mode=="query-failure")return 1;
    if(db->query=="SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED" || db->query=="SELECT CURRENT_USER()" || db->query=="SELECT CONNECTION_ID(),@@session.tx_isolation,@@session.autocommit,DATABASE(),@@socket" || db->query.find("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES")==0 || db->query.find("SELECT 'present' FROM information_schema.TRIGGERS")==0 || db->query.find("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,")==0 || db->query.find("SHOW CREATE TABLE ")==0 || db->query.find("SELECT 'subaccount'")==0)return 0;return 1;}
inline MYSQL_RES* mysql_store_result(MYSQL* db){auto r=new MYSQL_RES;const auto& q=db->query;
    if(q=="SELECT CURRENT_USER()")r->rows={{manual_mysql_mode=="wrong-principal"?"other@localhost":"root@localhost"}};
    else if(q.find("SELECT CONNECTION_ID()")==0){r->rows={{"12","READ-COMMITTED","1","qa",manual_mysql_mode=="wrong-socket"?"/other.sock":"/fictional.sock"}};if(manual_mysql_mode=="active-transaction")db->server_status=3;}
    else if(q.find("SELECT TABLE_NAME")==0){for(const auto& t:paymenter_qa::manual_financial_tables())r->rows.push_back({t,"InnoDB"});}
    else if(q.find("SHOW CREATE TABLE ")==0){auto t=q.substr(18);r->rows={{t,"fictional schema "+t}};}
    else if(q.find("SELECT 'subaccount'")==0){r->rows=fictional_manual_financial::rows();
        if(manual_mysql_mode=="oversize-cell")r->rows[0][3]=std::string(257,'x');
        if(manual_mysql_mode=="fetch-error")r->rows[0][3]="N";
        if(manual_mysql_mode=="foreign-row")r->rows[0][1]="999";
        if(manual_mysql_mode=="observation-revoke"){FILE* f=fopen((manual_mysql_root+"/revoked.v1").c_str(),"w");if(f){fputs("revoked\n",f);fclose(f);chmod((manual_mysql_root+"/revoked.v1").c_str(),0600);}}
    }
    return r;}
inline unsigned mysql_num_fields(MYSQL_RES* r){return r->rows.empty()?5:r->rows[0].size();}
inline unsigned long long mysql_num_rows(MYSQL_RES* r){return r->rows.size();}
inline MYSQL_ROW mysql_fetch_row(MYSQL_RES* r){if(r->cursor==r->rows.size())return nullptr;r->values.clear();r->lengths.clear();for(auto& s:r->rows[r->cursor++]){r->values.push_back(const_cast<char*>(s.c_str()));r->lengths.push_back(s.size());}return r->values.data();}
inline unsigned long* mysql_fetch_lengths(MYSQL_RES* r){return r->lengths.data();}
inline unsigned mysql_errno(MYSQL*){return manual_mysql_mode=="fetch-error"?1:0;}
inline void mysql_free_result(MYSQL_RES* r){delete r;}
