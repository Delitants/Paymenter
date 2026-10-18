// Fixed statement-snapshot reader. No credential channel or execution authority.
#pragma once
#include <algorithm>
#include <chrono>
#include <stdexcept>
#include <map>
#include <array>
#include <set>
#include <string>
#include <vector>
namespace paymenter_qa {
class ReaderError:public std::runtime_error {
public:explicit ReaderError(const char* code):std::runtime_error(code){}
};
inline void read_guard(bool ok,const char* code) {if(!ok)throw ReaderError(code);}
using ReadRows=std::vector<std::vector<std::string>>;
struct ReaderState {std::string connection,isolation,database,socket,autocommit;unsigned server_status;};
class ReadDatabase {
public:
    virtual ~ReadDatabase(){}
    virtual void configure()=0;
    virtual ReaderState state()=0;
    virtual ReadRows select(const std::string& fixed_sql)=0;
};
struct ReaderRequest {std::string run,case_id,facts,writer;std::vector<std::string> previous;};
struct ReaderAuditRequest {std::string run;std::map<std::string,std::string> facts;std::vector<std::string> previous;std::array<std::string,2> definitions;};
struct ReadResult {std::string connection;ReadRows rows;};
inline bool reader_hex(const std::string& s) {
    return s.size()==64 && s.find_first_not_of("0123456789abcdef")==std::string::npos;
}
inline bool reader_connection(const std::string& s) {
    return !s.empty() && s.size()<=20 && s[0]!='0' && s.find_first_not_of("0123456789")==std::string::npos;
}
class CommittedRead {
    ReadDatabase& db_;const std::string database_,socket_;
    const std::chrono::steady_clock::time_point end_;
    bool consumed_=false;
    void deadline()const {read_guard(std::chrono::steady_clock::now()<end_,"reader-deadline");}
    ReaderState state(const std::string& identity,bool configured) {
        deadline();auto s=db_.state();deadline();
        read_guard(reader_connection(s.connection) && (identity.empty() || identity==s.connection),"reader-connection");
        read_guard(s.database==database_ && s.socket==socket_,"reader-channel");
        // MySQL SERVER_STATUS_IN_TRANS=1, SERVER_STATUS_AUTOCOMMIT=2.
        read_guard(!(s.server_status&1),"reader-active-transaction");
        if(configured)read_guard(s.isolation=="READ-COMMITTED" && s.autocommit=="1" &&
            (s.server_status&2),"reader-isolation");
        return s;
    }
    ReaderState prepare() {
        read_guard(!consumed_,"reader-reused-session");consumed_=true;
        auto initial=state("",false);deadline();db_.configure();deadline();
        return state(initial.connection,true);
    }
    ReadRows query(const std::string& sql,const std::string& connection) {
        deadline();auto rows=db_.select(sql);deadline();(void)state(connection,true);return rows;
    }
public:
    CommittedRead(ReadDatabase& db,std::string database,std::string socket,std::chrono::steady_clock::time_point end):
        db_(db),database_(database),socket_(socket),end_(end){}
    ReaderState channel() {return prepare();}
    ReadResult audit(const ReaderAuditRequest& r) {
        read_guard(reader_hex(r.run) && r.facts.size()==4 && r.previous.size()<=64,"reader-audit-request");
        for(const auto& id:{"F1","F2","F3","F4"})read_guard(r.facts.count(id) && reader_hex(r.facts.at(id)),"reader-audit-facts");
        std::set<std::string> history;
        for(const auto& id:r.previous)read_guard(reader_connection(id) && history.insert(id).second,"reader-history");
        auto s=prepare();read_guard(!history.count(s.connection),"reader-independent");
        auto tables=query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('paymenter_qa_business_v1','paymenter_qa_journal_v1') ORDER BY TABLE_NAME",s.connection);
        read_guard(tables==ReadRows{{"paymenter_qa_business_v1","InnoDB"},{"paymenter_qa_journal_v1","InnoDB"}},"reader-tables");
        const std::string names="('paymenter_qa_business_v1','paymenter_qa_journal_v1')";
        read_guard(query("SELECT 'present' FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN "+names+" LIMIT 1",s.connection).empty(),"reader-audit-trigger");
        read_guard(query("SELECT 'present' FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names+" AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN "+names+") LIMIT 1",s.connection).empty(),"reader-audit-link");
        for(size_t i=0;i<2;++i) {
            const std::string table=i==0?"paymenter_qa_business_v1":"paymenter_qa_journal_v1";
            read_guard(!r.definitions[i].empty() && r.definitions[i].size()<=4096,"reader-audit-schema");
            read_guard(query("SHOW CREATE TABLE "+table,s.connection)==ReadRows{{table,r.definitions[i]}},"reader-audit-schema");
        }
        auto rows=query("SELECT 'business',run_id,case_id,CAST(amount AS CHAR),facts_hash FROM paymenter_qa_business_v1 UNION ALL SELECT 'journal',run_id,case_id,business_case_id,facts_hash FROM paymenter_qa_journal_v1 LIMIT 33",s.connection);
        read_guard(rows.size()<=8,"reader-audit-bound");std::set<std::string> keys;
        for(const auto& row:rows) {
            read_guard(row.size()==5 && row[1]==r.run && r.facts.count(row[2]) && row[4]==r.facts.at(row[2]),"reader-audit-binding");
            read_guard((row[0]=="business" && row[3]=="0.0100") || (row[0]=="journal" && row[3]==row[2]),"reader-audit-row");
            read_guard(keys.insert(row[0]+row[2]).second,"reader-audit-duplicate");
        }
        std::sort(rows.begin(),rows.end());return {s.connection,rows};
    }
    ReadResult observe(const ReaderRequest& r) {
        read_guard(reader_hex(r.run) && reader_hex(r.facts) &&
            (r.case_id=="F1" || r.case_id=="F2" || r.case_id=="F3" || r.case_id=="F4" || r.case_id=="F5") &&
            reader_connection(r.writer) && r.previous.size()<=64,"reader-request");
        auto previous=r.previous;
        for(const auto& id:previous)read_guard(reader_connection(id),"reader-history");
        std::sort(previous.begin(),previous.end());
        read_guard(std::adjacent_find(previous.begin(),previous.end())==previous.end(),"reader-history");
        auto s=prepare();
        read_guard(s.connection!=r.writer && std::find(previous.begin(),previous.end(),s.connection)==previous.end(),"reader-independent");
        auto tables=query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('paymenter_qa_business_v1','paymenter_qa_journal_v1') ORDER BY TABLE_NAME",s.connection);
        read_guard(tables==ReadRows{{"paymenter_qa_business_v1","InnoDB"},{"paymenter_qa_journal_v1","InnoDB"}},"reader-tables");
        // One SQL statement/read view for both tables. Separate autocommit SELECTs
        // could straddle a commit and manufacture a misleading split observation.
        auto rows=query("SELECT 'business',run_id,case_id,CAST(amount AS CHAR),facts_hash FROM paymenter_qa_business_v1 WHERE run_id='"+
            r.run+"' AND case_id='"+r.case_id+"' UNION ALL SELECT 'journal',run_id,case_id,business_case_id,facts_hash FROM paymenter_qa_journal_v1 WHERE run_id='"+
            r.run+"' AND case_id='"+r.case_id+"' LIMIT 3",s.connection);
        read_guard(rows.size()<=2,"reader-rows");bool business=false,journal=false;
        for(const auto& row:rows) {
            read_guard(row.size()==5 && row[1]==r.run && row[2]==r.case_id && row[4]==r.facts,"reader-row-binding");
            if(row[0]=="business") {read_guard(!business && row[3]=="0.0100","reader-business");business=true;}
            else {read_guard(row[0]=="journal" && !journal && row[3]==r.case_id,"reader-journal");journal=true;}
        }
        return {s.connection,rows};
    }
};
}
